<?php

declare(strict_types=1);

require_once __DIR__ . '/../private/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    json_response(405, false, 'Only GET requests are accepted.');
}
if (!is_configured()) {
    header('X-HelloWrandell-Content-Mode: static-fallback');
    json_response(200, false, 'Static portfolio content is active because the CMS has not been set up.');
}

try {
    $keys = [
        'profile',
        'roleLabels',
        'about',
        'experiences',
        'skillGroups',
        'certifications',
        'education',
        'processSteps',
        'strengths',
        'projectFilters',
    ];
    $content = [];
    $query = db()->query('SELECT content_key, content_json FROM site_content');
    foreach ($query->fetchAll() as $row) {
        if (!in_array((string) ($row['content_key'] ?? ''), $keys, true)) {
            continue;
        }
        $decoded = json_decode((string) ($row['content_json'] ?? ''), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $content[(string) $row['content_key']] = $decoded;
        }
    }

    $projects = db()->query(
        'SELECT * FROM projects WHERE published = 1 ORDER BY sort_order ASC, created_at ASC'
    )->fetchAll();
    $content['projects'] = array_map(
        static function (array $project): array {
            $technologies = json_decode((string) ($project['technologies_json'] ?? '[]'), true);
            return [
                'id' => (string) ($project['id'] ?? ''),
                'title' => (string) ($project['title'] ?? ''),
                'category' => (string) ($project['category'] ?? ''),
                'summary' => (string) ($project['summary'] ?? ''),
                'technologies' => is_array($technologies) ? $technologies : [],
                'overview' => (string) ($project['overview'] ?? ''),
                'problem' => (string) ($project['problem'] ?? ''),
                'objectives' => (string) ($project['objectives'] ?? ''),
                'role' => (string) ($project['role_text'] ?? ''),
                'targetUsers' => (string) ($project['target_users'] ?? ''),
                'requirements' => (string) ($project['requirements_text'] ?? ''),
                'planning' => (string) ($project['planning'] ?? ''),
                'solution' => (string) ($project['solution'] ?? ''),
                'implementation' => (string) ($project['implementation'] ?? ''),
                'testing' => (string) ($project['testing'] ?? ''),
                'security' => (string) ($project['security_text'] ?? ''),
                'challenges' => (string) ($project['challenges'] ?? ''),
                'results' => (string) ($project['results_text'] ?? ''),
                'lessons' => (string) ($project['lessons'] ?? ''),
                'repositoryUrl' => (string) ($project['repository_url'] ?? ''),
                'liveUrl' => (string) ($project['live_url'] ?? ''),
                'previewImage' => (string) ($project['preview_image'] ?? ''),
                'previewAlt' => (string) ($project['preview_alt'] ?? ''),
                'previewLabel' => (string) ($project['preview_label'] ?? ''),
                'isConcept' => (bool) ($project['is_concept'] ?? true),
            ];
        },
        $projects
    );
    header('Cache-Control: no-store, private');
    header('X-HelloWrandell-Content-Mode: cms');
    json_response(200, true, 'Portfolio content loaded.', ['content' => $content]);
} catch (Throwable $exception) {
    $requestId = operational_log('content-api', $exception);
    header('X-HelloWrandell-Content-Mode: static-fallback');
    header('X-HelloWrandell-Request-Id: ' . $requestId);
    json_response(200, false, 'Static portfolio content is active.', ['requestId' => $requestId]);
}
