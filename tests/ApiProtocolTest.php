<?php
/**
 * ApiProtocolTest: the OpenAI / Anthropic / native SSE dialects.
 *
 * Asserts the frame sequences an off-the-shelf SDK actually expects, because the
 * failure mode here is not an exception — it is a client that hangs, or that
 * silently accepts a failed run as a normal completion. Specifically covered:
 *
 *  - OpenAI: `chat.completion.chunk` objects, role in the first delta, a real
 *    `finish_reason`, and the `data: [DONE]` sentinel as a NON-JSON frame.
 *  - Anthropic: the full typed sequence message_start -> content_block_start ->
 *    ping -> delta… -> content_block_stop -> message_delta -> message_stop, with
 *    both an `event:` and a `data:` line on every frame.
 *  - Honesty: a failed or cancelled run must NOT report `stop` / `end_turn`,
 *    because that tells the client the answer is complete and trustworthy.
 *  - Multibyte safety: the answer splitter must not cut a 3-byte CJK or 2-byte
 *    Cyrillic character in half. `str_split` would, and the bug only appears in
 *    non-Latin locales — exactly where nobody is testing.
 *  - No invented usage: an absent or all-zero token count is omitted, never
 *    rendered as a free run.
 *
 * @project  RevoAp   @version 2.1.0   @since 2.1.0
 */
declare(strict_types=1);
require dirname(__DIR__) . '/src/autoload.php';
use App\Api\Protocol\StreamEncoder;
use App\Api\FormatAdapter;
$p=0;$f=0;
function ck(string $l,bool $ok,string $d=''){global $p,$f; if($ok){$p++;}else{$f++;echo "  FAIL $l".($d?" — $d":"")."\n";}}
$run=['run_id'=>'run_42','session_id'=>7,'status'=>'completed','created_at'=>'2026-10-15T10:00:00Z',
 'final_answer'=>'Consensus answer.','model'=>'openai/gpt-5.5','consensus'=>['score'=>0.91,'status'=>'agreement'],
 'usage'=>['prompt_tokens'=>1200,'completion_tokens'=>340,'total_tokens'=>1540,'cached_tokens'=>800,'reasoning_tokens'=>50]];

echo "== OpenAI dialect ==\n";
$fr=StreamEncoder::encode(FormatAdapter::FORMAT_OPENAI,$run);
ck('produces frames',count($fr)>=4,(string)count($fr));
ck('first frame has role delta',str_contains($fr[0],'"role":"assistant"'));
ck('no event: line in OpenAI frames (SDK routes on data only)',!str_contains($fr[0],'event:'));
ck('terminates with data: [DONE] sentinel',end($fr)==="data: [DONE]\n\n",var_export(end($fr),true));
ck('terminal frame carries finish_reason=stop',str_contains($fr[count($fr)-2],'"finish_reason":"stop"'));
ck('object is chat.completion.chunk',str_contains($fr[0],'"object":"chat.completion.chunk"'));
ck('id is chatcmpl-prefixed',str_contains($fr[0],'"id":"chatcmpl-run_42"'));
ck('created derived from the run timestamp, not now',str_contains($fr[0],'"created":'.strtotime('2026-10-15T10:00:00Z')));
ck('usage present with real counts',str_contains($fr[count($fr)-2],'"total_tokens":1540'));
ck('cached/reasoning details carried',str_contains($fr[count($fr)-2],'"cached_tokens":800')&&str_contains($fr[count($fr)-2],'"reasoning_tokens":50'));
ck('every frame ends with a blank line',!array_filter($fr,fn($x)=>!str_ends_with($x,"\n\n")));
ck('every data: line is valid JSON',!array_filter(array_slice($fr,0,-1),function($x){
  $j=substr($x,strpos($x,'data: ')+6); $j=rtrim($j); return json_decode($j,true)===null;}));
ck('model id slashes are NOT escaped',str_contains($fr[0],'"model":"openai/gpt-5.5"'));

echo "== Anthropic dialect ==\n";
$an=StreamEncoder::encode(FormatAdapter::FORMAT_ANTHROPIC,$run);
$types=[]; foreach($an as $x){ if(preg_match('/^event: (\S+)/m',$x,$m)) $types[]=$m[1]; }
ck('emits the full documented event sequence',$types===['message_start','content_block_start','ping','content_block_delta','content_block_stop','message_delta','message_stop'],implode(',',$types));
ck('message_start content is an empty ARRAY not null',str_contains($an[0],'"content":[]'));
ck('message_start carries input_tokens',str_contains($an[0],'"input_tokens":1200'));
ck('message_delta carries the FINAL output_tokens',str_contains($an[count($an)-2],'"output_tokens":340'));
ck('stop_reason is end_turn for a completed run',str_contains($an[count($an)-2],'"stop_reason":"end_turn"'));
ck('every frame has both event: and data:',!array_filter($an,fn($x)=>!str_contains($x,'event: ')||!str_contains($x,'data: ')));
ck('id is msg_-prefixed',str_contains($an[0],'"id":"msg_run_42"'));
ck('delta type is text_delta',str_contains($an[3],'"type":"text_delta"'));

echo "== Native dialect ==\n";
$na=StreamEncoder::encode(FormatAdapter::FORMAT_NATIVE,$run);
ck('emits result then done',str_contains($na[count($na)-2],'"type":"result"')&&str_contains(end($na),'"type":"done"'));
ck('result carries final_answer and consensus',str_contains($na[count($na)-2],'Consensus answer.')&&str_contains($na[count($na)-2],'"score":0.91'));

echo "== failure honesty ==\n";
// Sanity-check the fixture itself before asserting on it. `$run + [...]` would
// have kept the left operand's status and made every check below pass for the
// wrong reason — which is worse than failing, because it reports green.
$failedRun = array_merge($run, ['status' => 'failed']);
ck('the failed fixture actually overrides status', $failedRun['status'] === 'failed', $failedRun['status']);
ck('and the original is untouched', $run['status'] === 'completed');
$bad=StreamEncoder::encode(FormatAdapter::FORMAT_OPENAI,array_merge($run,['status'=>'failed','final_answer'=>'']));
ck('a failed run does NOT report finish_reason=stop',!str_contains($bad[count($bad)-2],'"finish_reason":"stop"'),'a client would trust a failed answer');
ck('a failed run reports content_filter',str_contains($bad[count($bad)-2],'"finish_reason":"content_filter"'));
$badA=StreamEncoder::encode(FormatAdapter::FORMAT_ANTHROPIC,array_merge($run,['status'=>'cancelled']));
ck('a cancelled run reports refusal, not end_turn',str_contains($badA[count($badA)-2],'"stop_reason":"refusal"'));

echo "== multibyte safety (the CJK/Cyrillic/Arabic splitter bug) ==\n";
$cjk=str_repeat('漢字テスト',300);           // 1500 chars, 3-byte each
$seg=StreamEncoder::segment($cjk,1024);
ck('a long CJK answer is segmented',count($seg)===2,(string)count($seg));
ck('segments reassemble exactly',implode('',$seg)===$cjk);
ck('no segment contains an invalid UTF-8 sequence',!array_filter($seg,fn($s)=>!mb_check_encoding($s,'UTF-8')));
$ar=str_repeat('العربية ',400);
$segA=StreamEncoder::segment($ar,1024);
ck('Arabic segments stay valid UTF-8',!array_filter($segA,fn($s)=>!mb_check_encoding($s,'UTF-8')));
ck('and reassemble exactly',implode('',$segA)===$ar);
$fr2=StreamEncoder::encode(FormatAdapter::FORMAT_OPENAI,array_merge($run,['final_answer'=>$cjk]));
$allValid=true; foreach(array_slice($fr2,0,-1) as $x){ $j=rtrim(substr($x,strpos($x,'data: ')+6)); if(json_decode($j,true)===null){$allValid=false;} }
ck('a CJK answer still yields all-valid JSON frames',$allValid);
ck('CJK is transmitted unescaped, not as \\uXXXX',!str_contains(implode('',$fr2),'\u6f22'));
ck('empty answer produces no content frames',count(StreamEncoder::encode(FormatAdapter::FORMAT_OPENAI,array_merge($run,['final_answer'=>''])))===3);
ck('segment("") is empty',StreamEncoder::segment('',100)===[]);
ck('segment respects maxChars=1',count(StreamEncoder::segment('abc',1))===3);

echo "== no usage invention ==\n";
$nu=StreamEncoder::encode(FormatAdapter::FORMAT_OPENAI,['run_id'=>'r','status'=>'completed','final_answer'=>'x','usage'=>null]);
ck('a run with no usage emits no usage object',!str_contains($nu[count($nu)-2],'"usage"'),'a fabricated $0.00 is worse than a gap');
$zu=StreamEncoder::encode(FormatAdapter::FORMAT_OPENAI,['run_id'=>'r','status'=>'completed','final_answer'=>'x','usage'=>['prompt_tokens'=>0,'completion_tokens'=>0,'total_tokens'=>0]]);
ck('all-zero usage is omitted rather than reported as free',!str_contains($zu[count($zu)-2],'"usage"'));

echo "\n=========================\n";
echo "Passes: {$p}  Failures: {$f}\n";
exit($f === 0 ? 0 : 1);
