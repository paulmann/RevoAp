<?php
/**
 * SkillController: REST CRUD endpoints for skills.
 *
 * @project  RevoAp
 * @author   Mikhail Deynekin <mikhail@deynekin.com>
 * @site     https://Deynekin.com
 * @email    mikhail@deynekin.com
 * @version  1.0.0
 * @since    2026-05-05
 */
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Helpers\JsonHelper;
use App\Http\Response;
use App\Skills\Skill;
use App\Skills\SkillRepository;

final class SkillController
{
    public function __construct(private readonly SkillRepository $repo) {}

    public function index(): string
    {
        $skills = array_map(static fn(Skill $s): array => $s->toArray(), $this->repo->all());
        return Response::json(['skills' => $skills]);
    }

    /** @param array<string,string> $params */
    public function show(array $params): string
    {
        $skill = $this->repo->findById((int)($params['id'] ?? 0));
        if ($skill === null) {
            return Response::json(['error' => 'not found'], 404);
        }
        return Response::json(['skill' => $skill->toArray()]);
    }

    public function create(): string
    {
        $body = $this->readJsonBody();
        if (empty($body['slug']) || empty($body['name'])) {
            return Response::json(['error' => 'slug and name are required'], 400);
        }
        $skill = new Skill(
            id:          null,
            slug:        (string)$body['slug'],
            name:        (string)$body['name'],
            description: (string)($body['description'] ?? ''),
            definition:  is_array($body['definition'] ?? null) ? $body['definition'] : ['roles' => []],
            isSystem:    false,
            useCount:    0,
        );
        $saved = $this->repo->save($skill);
        return Response::json(['skill' => $saved->toArray()], 201);
    }

    /** @param array<string,string> $params */
    public function delete(array $params): string
    {
        $this->repo->delete((int)($params['id'] ?? 0));
        return Response::json(['ok' => true]);
    }

    /** @return array<string,mixed> */
    private function readJsonBody(): array
    {
        $raw = (string)file_get_contents('php://input');
        if ($raw === '') {
            return $_POST;
        }
        $parsed = JsonHelper::tryDecode($raw);
        return is_array($parsed) ? $parsed : [];
    }
}
