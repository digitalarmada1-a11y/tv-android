<?php

declare(strict_types=1);

$catalogFile = __DIR__ . '/catalogo.json';
$manifestDir = __DIR__ . '/manifests';
$posterDir = __DIR__ . '/posters';

function respond(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT', $text);

    if ($converted !== false) {
        $text = $converted;
    }

    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $text = trim($text, '-');

    return $text !== '' ? $text : 'video';
}

function normalizeSearchText(string $text): string
{
    $text = strtolower(trim($text));
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT', $text);

    if ($converted !== false) {
        $text = $converted;
    }

    $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text) ?? '';
    $text = preg_replace('/\s+/', ' ', $text) ?? '';

    return trim($text);
}

function normalizeCategory(string $value, string $collectionTitle = '', string $episodeLabel = ''): string
{
    $category = strtolower(trim($value));

    if (in_array($category, ['series', 'serie'], true)) {
        return 'series';
    }

    if (in_array($category, ['movie', 'pelicula', 'pelicula ', 'película', 'film'], true)) {
        return 'movie';
    }

    if (trim($collectionTitle) !== '' || trim($episodeLabel) !== '') {
        return 'series';
    }

    return 'movie';
}

function inferTypeFromSource(string $source): string
{
    $clean = strtolower(strtok($source, '?') ?: $source);

    if (str_ends_with($clean, '.mpd')) {
        return 'dash';
    }

    if (str_ends_with($clean, '.m3u8')) {
        return 'hls';
    }

    if (str_ends_with($clean, '.mp4') || str_ends_with($clean, '.webm') || str_ends_with($clean, '.mov')) {
        return 'video';
    }

    return 'stream';
}

function createItemId(string $title): string
{
    return slugify($title) . '-' . substr(bin2hex(random_bytes(6)), 0, 12);
}

function normalizeReference(string $value): string
{
    return trim($value);
}

function isValidReference(string $value): bool
{
    if ($value === '') {
        return false;
    }

    if (filter_var($value, FILTER_VALIDATE_URL)) {
        return true;
    }

    return preg_match('/^[A-Za-z0-9_\\.\\-\\/:%?=&+#]+$/', $value) === 1;
}

function fetchRemoteBody(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => implode("\r\n", [
                'User-Agent: Mozilla/5.0',
                'Accept: application/json,text/plain,text/html,image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            ]),
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    return $body === false ? '' : $body;
}

function readCatalog(string $catalogFile): array
{
    if (!file_exists($catalogFile)) {
        return [];
    }

    $raw = file_get_contents($catalogFile);

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? $decoded : [];
}

function normalizeItem(array $item, int $index): array
{
    $source = normalizeReference((string) ($item['source'] ?? $item['manifest'] ?? ''));
    $title = trim((string) ($item['title'] ?? ('Video ' . ($index + 1))));
    $description = trim((string) ($item['description'] ?? ''));
    $poster = trim((string) ($item['poster'] ?? ''));
    $collectionTitle = trim((string) ($item['collectionTitle'] ?? ''));
    $episodeLabel = trim((string) ($item['episodeLabel'] ?? ''));
    $category = normalizeCategory((string) ($item['category'] ?? ''), $collectionTitle, $episodeLabel);
    $id = trim((string) ($item['id'] ?? ''));
    $createdAt = trim((string) ($item['createdAt'] ?? ''));
    $updatedAt = trim((string) ($item['updatedAt'] ?? ''));

    if ($id === '') {
        $id = createItemId($title);
    }

    if ($createdAt === '') {
        $createdAt = gmdate('c');
    }

    if ($updatedAt === '') {
        $updatedAt = $createdAt;
    }

    return [
        'id' => $id,
        'title' => $title,
        'description' => $description,
        'source' => $source,
        'manifest' => $source,
        'poster' => $poster,
        'collectionTitle' => $collectionTitle,
        'episodeLabel' => $episodeLabel,
        'category' => $category,
        'type' => trim((string) ($item['type'] ?? inferTypeFromSource($source))),
        'createdAt' => $createdAt,
        'updatedAt' => $updatedAt,
    ];
}

function normalizeCatalog(array $items): array
{
    $normalized = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $normalized[] = normalizeItem($item, $index);
    }

    return $normalized;
}

function saveCatalog(string $catalogFile, array $items): void
{
    $payload = json_encode(
        array_values($items),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($payload === false) {
        respond(500, ['ok' => false, 'message' => 'No se pudo guardar el catalogo.']);
    }

    file_put_contents($catalogFile, $payload . PHP_EOL);
}

function findItemIndex(array $catalog, string $id): int
{
    foreach ($catalog as $index => $item) {
        if (($item['id'] ?? '') === $id) {
            return $index;
        }
    }

    return -1;
}

function cleanManagedFile(string $relativePath, string $baseDir, string $expectedPrefix): void
{
    $relativePath = trim($relativePath);

    if ($relativePath === '' || !str_starts_with($relativePath, $expectedPrefix . '/')) {
        return;
    }

    $filename = basename($relativePath);
    $target = $baseDir . DIRECTORY_SEPARATOR . $filename;

    if (is_file($target)) {
        unlink($target);
    }
}

function resolveStreamSource(string $manifestDir): array
{
    $sourceUrl = normalizeReference((string) ($_POST['source_url'] ?? $_POST['manifest_url'] ?? ''));
    $manifestXml = trim((string) ($_POST['manifest_xml'] ?? ''));

    if ($sourceUrl !== '') {
        if (!isValidReference($sourceUrl)) {
            respond(400, ['ok' => false, 'message' => 'La fuente indicada no parece valida.']);
        }

        return [
            'source' => $sourceUrl,
            'type' => inferTypeFromSource($sourceUrl),
        ];
    }

    if (isset($_FILES['manifest_file']) && ($_FILES['manifest_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $content = file_get_contents($_FILES['manifest_file']['tmp_name']);

        if ($content === false || stripos($content, '<MPD') === false) {
            respond(400, ['ok' => false, 'message' => 'El archivo subido no parece un MPD valido.']);
        }

        ensureDirectory($manifestDir);
        $filename = 'manifest-' . substr(bin2hex(random_bytes(6)), 0, 12) . '.mpd';
        $target = $manifestDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($target, $content);

        return [
            'source' => 'manifests/' . $filename,
            'type' => 'dash',
        ];
    }

    if ($manifestXml !== '') {
        if (stripos($manifestXml, '<MPD') === false) {
            respond(400, ['ok' => false, 'message' => 'El XML pegado no parece un MPD valido.']);
        }

        ensureDirectory($manifestDir);
        $filename = 'manifest-' . substr(bin2hex(random_bytes(6)), 0, 12) . '.mpd';
        $target = $manifestDir . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($target, $manifestXml);

        return [
            'source' => 'manifests/' . $filename,
            'type' => 'dash',
        ];
    }

    respond(400, ['ok' => false, 'message' => 'Debes poner un link, pegar XML MPD o subir un MPD.']);
}

function getImageExtensionFromBinary(string $binary): string
{
    $info = @getimagesizefromstring($binary);

    if ($info === false) {
        return '';
    }

    $mime = (string) ($info['mime'] ?? '');

    return match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        default => '',
    };
}

function cloneLocalPoster(string $posterValue, string $posterDir): string
{
    $posterValue = trim($posterValue);

    if (!str_starts_with($posterValue, 'posters/')) {
        return '';
    }

    $source = $posterDir . DIRECTORY_SEPARATOR . basename($posterValue);

    if (!is_file($source)) {
        return '';
    }

    $extension = pathinfo($source, PATHINFO_EXTENSION) ?: 'jpg';
    $filename = 'poster-' . substr(bin2hex(random_bytes(6)), 0, 12) . '.' . $extension;
    $target = $posterDir . DIRECTORY_SEPARATOR . $filename;

    if (!copy($source, $target)) {
        return $posterValue;
    }

    return 'posters/' . $filename;
}

function downloadPosterToLocal(string $posterUrl, string $posterDir): string
{
    if (!filter_var($posterUrl, FILTER_VALIDATE_URL)) {
        return '';
    }

    $binary = fetchRemoteBody($posterUrl);

    if ($binary === '') {
        return $posterUrl;
    }

    $extension = getImageExtensionFromBinary($binary);

    if ($extension === '') {
        return $posterUrl;
    }

    ensureDirectory($posterDir);
    $filename = 'poster-' . substr(bin2hex(random_bytes(6)), 0, 12) . '.' . $extension;
    $target = $posterDir . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($target, $binary);

    return 'posters/' . $filename;
}

function resolvePosterReference(string $posterValue, string $posterDir): string
{
    $posterValue = trim($posterValue);

    if ($posterValue === '') {
        return '';
    }

    if (str_starts_with($posterValue, 'posters/')) {
        return cloneLocalPoster($posterValue, $posterDir);
    }

    if (filter_var($posterValue, FILTER_VALIDATE_URL)) {
        return downloadPosterToLocal($posterValue, $posterDir);
    }

    return '';
}

function buildImdbSuggestionUrl(string $title): string
{
    $query = normalizeSearchText($title);

    if ($query === '') {
        return '';
    }

    $first = $query[0];

    return 'https://v2.sg.media-imdb.com/suggestion/' . rawurlencode($first) . '/' . rawurlencode($query) . '.json';
}

function scoreImdbSuggestion(string $query, array $item): float
{
    $normalizedQuery = normalizeSearchText($query);
    $normalizedTitle = normalizeSearchText((string) ($item['l'] ?? ''));
    $score = 0.0;

    if ($normalizedTitle === '') {
        return $score;
    }

    if ($normalizedTitle === $normalizedQuery) {
        $score += 200;
    }

    if (str_starts_with($normalizedTitle, $normalizedQuery)) {
        $score += 90;
    }

    if ($normalizedQuery !== '' && str_contains($normalizedTitle, $normalizedQuery)) {
        $score += 60;
    }

    similar_text($normalizedQuery, $normalizedTitle, $percent);
    $score += $percent;

    if (!empty($item['i']['imageUrl'])) {
        $score += 25;
    }

    if (in_array((string) ($item['qid'] ?? ''), ['movie', 'tvSeries', 'tvMiniSeries', 'tvMovie'], true)) {
        $score += 10;
    }

    return $score;
}

function lookupImdbPoster(string $title): ?array
{
    $url = buildImdbSuggestionUrl($title);

    if ($url === '') {
        return null;
    }

    $body = fetchRemoteBody($url);

    if ($body === '') {
        return null;
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded) || !is_array($decoded['d'] ?? null)) {
        return null;
    }

    $bestMatch = null;
    $bestScore = -1.0;

    foreach ($decoded['d'] as $item) {
        if (!is_array($item) || empty($item['l']) || empty($item['i']['imageUrl'])) {
            continue;
        }

        $score = scoreImdbSuggestion($title, $item);

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = [
                'imdbId' => (string) ($item['id'] ?? ''),
                'title' => (string) ($item['l'] ?? ''),
                'year' => isset($item['y']) ? (int) $item['y'] : null,
                'posterUrl' => (string) ($item['i']['imageUrl'] ?? ''),
            ];
        }
    }

    return $bestMatch;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Metodo no permitido.']);
}

$action = strtolower(trim((string) ($_POST['action'] ?? 'create')));
$catalog = normalizeCatalog(readCatalog($catalogFile));
saveCatalog($catalogFile, $catalog);

if ($action === 'poster_lookup') {
    $title = trim((string) ($_POST['title'] ?? ''));

    if ($title === '') {
        respond(400, ['ok' => false, 'message' => 'Falta el titulo para buscar poster.']);
    }

    $match = lookupImdbPoster($title);

    if ($match === null || empty($match['posterUrl'])) {
        respond(200, ['ok' => true, 'found' => false, 'message' => 'No encontre poster en IMDb para ese titulo.']);
    }

    respond(200, [
        'ok' => true,
        'found' => true,
        'message' => 'Poster encontrado en IMDb.',
        'match' => $match,
    ]);
}

if ($action === 'create') {
    $title = trim((string) ($_POST['title'] ?? ''));
    $collectionTitle = trim((string) ($_POST['collection_title'] ?? ''));
    $episodeLabel = trim((string) ($_POST['episode_label'] ?? ''));
    $category = normalizeCategory((string) ($_POST['category'] ?? ''), $collectionTitle, $episodeLabel);

    if ($title === '') {
        respond(400, ['ok' => false, 'message' => 'Falta el titulo.']);
    }

    $sourceInfo = resolveStreamSource($manifestDir);
    $posterValue = trim((string) ($_POST['poster_url'] ?? ''));
    $now = gmdate('c');

    $item = [
        'id' => createItemId($title),
        'title' => $title,
        'description' => '',
        'source' => $sourceInfo['source'],
        'manifest' => $sourceInfo['source'],
        'poster' => resolvePosterReference($posterValue, $posterDir),
        'collectionTitle' => $collectionTitle,
        'episodeLabel' => $episodeLabel,
        'category' => $category,
        'type' => $sourceInfo['type'],
        'createdAt' => $now,
        'updatedAt' => $now,
    ];

    $catalog[] = $item;
    saveCatalog($catalogFile, $catalog);

    respond(200, [
        'ok' => true,
        'message' => $item['poster'] !== '' ? 'Item guardado con poster.' : 'Item guardado.',
        'item' => $item,
        'count' => count($catalog),
    ]);
}

if ($action === 'delete') {
    $id = trim((string) ($_POST['id'] ?? ''));
    $index = findItemIndex($catalog, $id);

    if ($index < 0) {
        respond(404, ['ok' => false, 'message' => 'No se encontro el item.']);
    }

    $item = $catalog[$index];
    cleanManagedFile((string) ($item['source'] ?? ''), $manifestDir, 'manifests');
    cleanManagedFile((string) ($item['poster'] ?? ''), $posterDir, 'posters');

    array_splice($catalog, $index, 1);
    saveCatalog($catalogFile, $catalog);

    respond(200, [
        'ok' => true,
        'message' => 'Item borrado.',
        'count' => count($catalog),
    ]);
}

if ($action === 'move') {
    $id = trim((string) ($_POST['id'] ?? ''));
    $direction = strtolower(trim((string) ($_POST['direction'] ?? 'up')));
    $index = findItemIndex($catalog, $id);

    if ($index < 0) {
        respond(404, ['ok' => false, 'message' => 'No se encontro el item.']);
    }

    $targetIndex = $direction === 'down' ? $index + 1 : $index - 1;

    if (!isset($catalog[$targetIndex])) {
        respond(200, [
            'ok' => true,
            'message' => 'Ese item ya esta en el borde.',
            'count' => count($catalog),
        ]);
    }

    $current = $catalog[$index];
    $catalog[$index] = $catalog[$targetIndex];
    $catalog[$targetIndex] = $current;
    saveCatalog($catalogFile, $catalog);

    respond(200, [
        'ok' => true,
        'message' => 'Orden actualizado.',
        'count' => count($catalog),
    ]);
}

respond(400, ['ok' => false, 'message' => 'Accion no soportada.']);
