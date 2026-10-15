<?php
/**
 * PipelineRejectedException: thrown by the engine when the user has
 * issued a `reject` control action for the running session. The
 * controller handles it as a non-fatal terminal event so the SSE
 * stream emits PIPELINE_REJECTED instead of PIPELINE_FATAL.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-08
 */
declare(strict_types=1);

namespace App\Pipeline;

final class PipelineRejectedException extends \RuntimeException
{
}
