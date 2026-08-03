<?php
declare(strict_types=1);

/**
 * Interaktiver, nur lesender SYR-Connect-Cloud-Client.
 *
 * Start:
 *   php json-syrconnect-loop.php
 *   php json-syrconnect-loop.php --once=all
 *   php json-syrconnect-loop.php --params=task_syrconnect_params.json
 */

date_default_timezone_set('Europe/Berlin');

const LOGIN_PATH = '/Login.aspx';
const USER_FIELD = 'ctl00$cphMainContent$Login1$UserName';
const PASSWORD_FIELD = 'ctl00$cphMainContent$Login1$Password';
const CAPTCHA_FIELD = 'ctl00$cphMainContent$Login1$rcCaptcha1$CaptchaTextBox';
const LOGIN_BUTTON_FIELD = 'ctl00$cphMainContent$Login1$LoginButton';

try {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Die PHP-cURL-Erweiterung ist nicht aktiv.');
    }

    $options = getopt('', ['params:', 'once:', 'help']);
    $baseDir = __DIR__;
    $paramsFile = resolvePath((string)($options['params'] ?? 'task_syrconnect_params.json'), $baseDir);
    $params = loadJson($paramsFile);
    $client = new SyrConnectClient($params, $baseDir);

    if (isset($options['help'])) {
        printHelp($paramsFile);
        exit(0);
    }

    if (isset($options['once'])) {
        executeCommand($client, trim((string)$options['once']));
        exit(0);
    }

    echo 'SYR Connect Cloud Loop' . PHP_EOL;
    echo 'Parameter: ' . $paramsFile . PHP_EOL;
    echo 'Passwort:   [nicht angezeigt]' . PHP_EOL;
    printCommands();

    while (true) {
        echo PHP_EOL . 'syrconnect> ';
        $line = fgets(STDIN);
        if ($line === false) {
            echo PHP_EOL;
            break;
        }
        $command = trim($line);
        if ($command === '') {
            continue;
        }
        if (in_array(strtolower($command), ['q', 'quit', 'exit', 'ende'], true)) {
            break;
        }
        try {
            executeCommand($client, $command);
        } catch (Throwable $error) {
            fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
        }
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'FEHLER: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

function executeCommand(SyrConnectClient $client, string $command): void
{
    $parts = preg_split('/\s+/', trim($command), 3) ?: [];
    $name = strtolower((string)($parts[0] ?? ''));

    if (in_array($name, ['?', 'help', 'hilfe'], true)) {
        printCommands();
        return;
    }
    if ($name === 'login') {
        $client->login(true);
        echo 'Anmeldung erfolgreich.' . PHP_EOL;
        return;
    }
    if ($name === 'projects' || $name === 'projekte') {
        printJson($client->projects());
        return;
    }
    if ($name === 'status') {
        printJson($client->status());
        return;
    }
    if ($name === 'all' || $name === 'alle') {
        printJson($client->collectAll());
        return;
    }
    if ($name === 'save' || $name === 'speichern') {
        $file = isset($parts[1]) ? $parts[1] : null;
        $target = $client->saveAll($file);
        echo 'Export gespeichert: ' . $target . PHP_EOL;
        return;
    }
    if ($name === 'get' && isset($parts[1])) {
        printJson($client->readPage($parts[1]));
        return;
    }
    if ($name === 'raw' && isset($parts[1])) {
        echo $client->getAuthenticated($parts[1]);
        return;
    }
    if ($name === 'session') {
        printJson($client->sessionInfo());
        return;
    }

    throw new InvalidArgumentException('Unbekannter Befehl. Mit ? wird die Hilfe angezeigt.');
}

function printCommands(): void
{
    echo PHP_EOL . 'Befehle:' . PHP_EOL;
    echo '  login                 neu anmelden (CAPTCHA wird als PNG gespeichert)' . PHP_EOL;
    echo '  projects              Projekte und erreichbare Projektlinks anzeigen' . PHP_EOL;
    echo '  status                Status- und Gerätewerte auslesen' . PHP_EOL;
    echo '  all                    alle erreichbaren Cloud-Seiten lesen' . PHP_EOL;
    echo '  save [DATEI]           kompletten JSON-Export speichern' . PHP_EOL;
    echo '  get PFAD-ODER-URL      eine Seite strukturiert lesen' . PHP_EOL;
    echo '  raw PFAD-ODER-URL      unverändertes HTML anzeigen' . PHP_EOL;
    echo '  session               Sitzungsstatus ohne Geheimnisse anzeigen' . PHP_EOL;
    echo '  q                      beenden' . PHP_EOL;
}

function printHelp(string $paramsFile): void
{
    echo 'SYR Connect Cloud Loop' . PHP_EOL;
    echo '  php json-syrconnect-loop.php' . PHP_EOL;
    echo '  php json-syrconnect-loop.php --once=all' . PHP_EOL;
    echo '  php json-syrconnect-loop.php --params=DATEI' . PHP_EOL;
    echo 'Parameterdatei: ' . $paramsFile . PHP_EOL;
    printCommands();
}

final class SyrConnectClient
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private string $cookieFile;
    private string $captchaFile;
    private string $outputFile;
    private int $timeout;
    private int $maxPages;
    private ?string $projectUrl = null;

    public function __construct(array $params, string $baseDir)
    {
        $this->baseUrl = rtrim((string)($params['baseUrl'] ?? 'https://syrconnect.de'), '/');
        $this->username = trim((string)($params['username'] ?? ''));
        $this->password = (string)($params['password'] ?? '');
        $this->cookieFile = resolvePath((string)($params['cookieFile'] ?? 'syrconnect-cookies.txt'), $baseDir);
        $this->captchaFile = resolvePath((string)($params['captchaFile'] ?? 'syrconnect-captcha.png'), $baseDir);
        $this->outputFile = resolvePath((string)($params['outputFile'] ?? 'syrconnect-cloud-export.json'), $baseDir);
        $this->timeout = max(5, (int)($params['timeout'] ?? 30));
        $this->maxPages = max(5, min(100, (int)($params['maxPages'] ?? 40)));
        if ($this->username === '' || $this->password === '') {
            throw new RuntimeException('username oder password fehlt in der Parameterdatei.');
        }
    }

    public function login(bool $force = false): void
    {
        if (!$force && $this->isLoggedIn()) {
            return;
        }

        if ($force && is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }

        $loginPage = $this->request('GET', LOGIN_PATH);
        $fields = hiddenFields($loginPage['body']);
        $captchaUrl = firstMatch($loginPage['body'], '~<img[^>]+id="[^"]*CaptchaImageUP"[^>]+src="([^"]+)"~i');
        if ($captchaUrl === null) {
            throw new RuntimeException('CAPTCHA-Bild wurde auf der Loginseite nicht gefunden.');
        }

        $image = $this->request('GET', html_entity_decode($captchaUrl, ENT_QUOTES | ENT_HTML5));
        if (file_put_contents($this->captchaFile, $image['body']) === false) {
            throw new RuntimeException('CAPTCHA konnte nicht gespeichert werden: ' . $this->captchaFile);
        }

        echo 'CAPTCHA-Datei: ' . $this->captchaFile . PHP_EOL;
        echo 'Bitte Bild öffnen und den fünfstelligen Sicherheitscode eingeben: ';
        $captcha = trim((string)fgets(STDIN));
        if ($captcha === '') {
            throw new RuntimeException('Kein Sicherheitscode eingegeben.');
        }

        $fields[USER_FIELD] = $this->username;
        $fields[PASSWORD_FIELD] = $this->password;
        $fields[CAPTCHA_FIELD] = $captcha;
        $fields[LOGIN_BUTTON_FIELD] = 'Anmelden';
        $fields['ctl00$cphMainContent$Login1$RememberMe'] = 'on';

        $result = $this->request('POST', LOGIN_PATH, $fields);
        if (stripos($result['body'], 'cphMainContent_Login1_Password') !== false
            || stripos($result['url'], '/Login.aspx') !== false) {
            throw new RuntimeException('Login fehlgeschlagen. Sicherheitscode oder Zugangsdaten prüfen.');
        }
        $this->projectUrl = null;
    }

    public function getAuthenticated(string $pathOrUrl): string
    {
        $this->login();
        $result = $this->request('GET', $pathOrUrl);
        if (stripos($result['body'], 'cphMainContent_Login1_Password') !== false) {
            $this->login(true);
            $result = $this->request('GET', $pathOrUrl);
        }
        return $result['body'];
    }

    public function projects(): array
    {
        $html = $this->getAuthenticated('/Admin/Default.aspx');
        $page = parsePage($html, $this->baseUrl . '/Admin/Default.aspx');
        $links = array_values(array_filter($page['links'], static fn(array $link): bool =>
            str_contains($link['url'], 'ProjectManagement.aspx')));
        if ($links !== []) {
            $this->projectUrl = $links[0]['url'];
        }
        return ['generatedAt' => date(DATE_ATOM), 'projects' => $links, 'page' => $page['fields']];
    }

    public function status(): array
    {
        $url = $this->discoverProjectUrl();
        return $this->readPage($url);
    }

    public function readPage(string $pathOrUrl): array
    {
        $url = absoluteUrl($this->baseUrl . '/', $pathOrUrl);
        $html = $this->getAuthenticated($url);
        return parsePage($html, $url);
    }

    public function collectAll(): array
    {
        $start = $this->discoverProjectUrl();
        $queue = [$this->baseUrl . '/Admin/Default.aspx', $start];
        $seen = [];
        $pages = [];
        while ($queue !== [] && count($seen) < $this->maxPages) {
            $url = array_shift($queue);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $page = $this->readPage($url);
            $pages[] = $page;
            foreach ($page['links'] as $link) {
                $next = $link['url'];
                if (str_starts_with($next, $this->baseUrl . '/Admin/')
                    && !isset($seen[$next])
                    && !str_contains($next, 'javascript:')) {
                    $queue[] = $next;
                }
            }
        }
        return [
            'ok' => true,
            'schemaVersion' => 1,
            'generatedAt' => date(DATE_ATOM),
            'pageCount' => count($pages),
            'pages' => $pages,
        ];
    }

    public function saveAll(?string $file = null): string
    {
        $target = $file === null || trim($file) === ''
            ? $this->outputFile
            : resolvePath($file, __DIR__);
        $json = json_encode($this->collectAll(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        $tmp = $target . '.tmp';
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException('Export konnte nicht geschrieben werden: ' . $target);
        }
        return $target;
    }

    public function sessionInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'loggedIn' => $this->isLoggedIn(),
            'cookieFileExists' => is_file($this->cookieFile),
            'captchaFile' => $this->captchaFile,
            'outputFile' => $this->outputFile,
        ];
    }

    private function discoverProjectUrl(): string
    {
        if ($this->projectUrl !== null) {
            return $this->projectUrl;
        }
        $projects = $this->projects();
        $url = $projects['projects'][0]['url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new RuntimeException('Kein Projektlink gefunden.');
        }
        return $this->projectUrl = $url;
    }

    private function isLoggedIn(): bool
    {
        if (!is_file($this->cookieFile) || filesize($this->cookieFile) === 0) {
            return false;
        }
        try {
            $result = $this->request('GET', '/Admin/Default.aspx');
            return stripos($result['body'], 'Abmelden') !== false
                && stripos($result['body'], 'cphMainContent_Login1_Password') === false;
        } catch (Throwable) {
            return false;
        }
    }

    private function request(string $method, string $pathOrUrl, array $fields = []): array
    {
        $url = absoluteUrl($this->baseUrl . '/', $pathOrUrl);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('cURL konnte nicht initialisiert werden.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 8,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) SYRConnectLoop/1.0',
            CURLOPT_REFERER => $this->baseUrl . LOGIN_PATH,
            CURLOPT_ENCODING => '',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        $body = curl_exec($ch);
        if ($body === false) {
            $message = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP-Fehler: ' . $message);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        if ($status >= 400) {
            throw new RuntimeException('SYR Connect antwortet mit HTTP ' . $status . '.');
        }
        return ['body' => $body, 'status' => $status, 'url' => $finalUrl];
    }
}

function parsePage(string $html, string $url): array
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);

    $links = [];
    foreach ($xpath->query('//a[@href]') ?: [] as $node) {
        $href = trim((string)$node->getAttribute('href'));
        if ($href === '' || $href === '#' || str_starts_with(strtolower($href), 'javascript:')) {
            continue;
        }
        $links[] = [
            'text' => cleanText($node->textContent),
            'url' => absoluteUrl($url, html_entity_decode($href, ENT_QUOTES | ENT_HTML5)),
        ];
    }

    $fields = [];
    foreach ($xpath->query('//span[normalize-space()] | //td[normalize-space()] | //legend[normalize-space()] | //h1[normalize-space()] | //h2[normalize-space()]') ?: [] as $node) {
        $text = cleanText($node->textContent);
        if ($text === '' || mb_strlen($text) > 250) {
            continue;
        }
        $key = trim((string)$node->getAttribute('id'));
        if ($key === '') {
            $key = 'text.' . count($fields);
        }
        $fields[$key] = $text;
    }

    return [
        'url' => $url,
        'title' => cleanText((string)($xpath->evaluate('string(//title)') ?: '')),
        'fields' => $fields,
        'links' => deduplicateLinks($links),
    ];
}

function hiddenFields(string $html): array
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $fields = [];
    foreach ($dom->getElementsByTagName('input') as $input) {
        if (strtolower($input->getAttribute('type')) !== 'hidden') {
            continue;
        }
        $name = $input->getAttribute('name');
        if ($name !== '') {
            $fields[$name] = $input->getAttribute('value');
        }
    }
    return $fields;
}

function deduplicateLinks(array $links): array
{
    $result = [];
    $seen = [];
    foreach ($links as $link) {
        $key = $link['url'] . '|' . $link['text'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = $link;
        }
    }
    return $result;
}

function firstMatch(string $text, string $pattern): ?string
{
    return preg_match($pattern, $text, $matches) === 1 ? (string)$matches[1] : null;
}

function absoluteUrl(string $base, string $path): string
{
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    $parts = parse_url($base);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        throw new InvalidArgumentException('Ungültige Basis-URL: ' . $base);
    }
    $root = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($path, '/')) {
        return $root . $path;
    }
    $basePath = (string)($parts['path'] ?? '/');
    $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';
    $segments = explode('/', $directory . $path);
    $normalized = [];
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($normalized);
        } else {
            $normalized[] = $segment;
        }
    }
    return $root . '/' . implode('/', $normalized);
}

function resolvePath(string $path, string $baseDir): string
{
    if (preg_match('~^[A-Za-z]:[\\\\/]~', $path) || str_starts_with($path, '/')) {
        return $path;
    }
    return rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $path;
}

function loadJson(string $file): array
{
    if (!is_file($file)) {
        throw new RuntimeException('Parameterdatei nicht gefunden: ' . $file);
    }
    $data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Parameterdatei enthält kein JSON-Objekt.');
    }
    return $data;
}

function cleanText(string $text): string
{
    return trim((string)preg_replace('/\s+/u', ' ', $text));
}

function printJson(mixed $value): void
{
    echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
}
