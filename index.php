<?php
declare(strict_types=1);

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'use_strict_mode' => true,
]);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");

const DATA_DIR = __DIR__ . '/storage';
const STORE_FILE = DATA_DIR . '/blog.json';
const LOCK_FILE = DATA_DIR . '/blog.lock';

final class RedirectException extends RuntimeException
{
    public function __construct(public readonly string $path)
    {
        parent::__construct('Redirect after saving.');
    }
}

function text_limit(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function defaults(): array
{
    return [
        'settings' => [
            'title' => 'My Flat-file Blog',
            'tagline' => 'Thoughts, stories, and ideas.',
            'admin_path' => 'admin',
            'registration_open' => true,
            'comments_enabled' => true,
            'comments_moderated' => true,
            'comments_visibility' => 'all',
        ],
        'users' => [],
        'posts' => [],
        'pages' => [],
        'comments' => [],
        'polls' => [],
        'forms' => [],
        'messages' => [],
    ];
}

function read_store(): array
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('The storage directory could not be created.');
    }
    $lock = fopen(LOCK_FILE, 'c');
    if ($lock === false || !flock($lock, LOCK_SH)) {
        throw new RuntimeException('The content store is unavailable.');
    }
    try {
        if (!is_file(STORE_FILE)) {
            return defaults();
        }
        $decoded = json_decode((string) file_get_contents(STORE_FILE), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The content store contains invalid JSON.');
        }
        return array_replace_recursive(defaults(), $decoded);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function update_store(callable $callback): mixed
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0750, true) && !is_dir(DATA_DIR)) {
        throw new RuntimeException('The storage directory could not be created.');
    }
    $lock = fopen(LOCK_FILE, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('The content store is unavailable.');
    }
    try {
        $store = is_file(STORE_FILE)
            ? json_decode((string) file_get_contents(STORE_FILE), true)
            : defaults();
        if (!is_array($store)) {
            throw new RuntimeException('The content store contains invalid JSON.');
        }
        $store = array_replace_recursive(defaults(), $store);
        $GLOBALS['store_transaction'] = true;
        try {
            $result = $callback($store);
        } catch (RedirectException $exception) {
            $result = $exception->path;
        } finally {
            $GLOBALS['store_transaction'] = false;
        }
        $json = json_encode($store, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Content could not be encoded.');
        }
        $temp = tempnam(DATA_DIR, 'blog-');
        if ($temp === false || file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Content could not be saved.');
        }
        chmod($temp, 0640);
        if (!rename($temp, STORE_FILE)) {
            @unlink($temp);
            throw new RuntimeException('Content could not be saved.');
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    if (!isset($_POST['csrf']) || !hash_equals(csrf_token(), (string) $_POST['csrf'])) {
        http_response_code(419);
        exit('Your session expired. Please go back and try again.');
    }
}

function redirect(string $path): never
{
    if ($GLOBALS['store_transaction'] ?? false) {
        throw new RedirectException($path);
    }
    header('Location: ' . $path, true, 303);
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function require_admin(): void
{
    if (!is_admin()) {
        flash('Please sign in as an administrator.', 'error');
        redirect('/login');
    }
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: bin2hex(random_bytes(4));
}

function unique_slug(array $items, string $title, ?string $except = null): string
{
    $base = slugify($title);
    $slug = $base;
    $number = 2;
    while (isset($items[$slug]) && $slug !== $except) {
        $slug = $base . '-' . $number++;
    }
    return $slug;
}

function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function render(string $title, string $content, array $store): void
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    $user = current_user();
    $adminPath = '/' . trim((string) $store['settings']['admin_path'], '/');
    $navPages = array_filter($store['pages'], static fn(array $page): bool =>
        ($page['status'] ?? '') === 'published' && ($page['privacy'] ?? 'public') === 'public'
    );
    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= e($store['settings']['tagline']) ?>">
    <title><?= e($title) ?> · <?= e($store['settings']['title']) ?></title>
    <link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/"><span class="brand-mark">F</span><span><?= e($store['settings']['title']) ?><small><?= e($store['settings']['tagline']) ?></small></span></a>
    <nav aria-label="Main navigation">
        <a href="/">Blog</a>
        <?php foreach ($navPages as $page): ?><a href="/page/<?= e($page['slug']) ?>"><?= e($page['title']) ?></a><?php endforeach; ?>
        <?php if ($user): ?><span class="nav-user">Hi, <?= e($user['name']) ?></span><a href="/logout">Sign out</a>
        <?php else: ?><a href="/login">Sign in</a><?php endif; ?>
        <?php if (is_admin()): ?><a class="nav-admin" href="<?= e($adminPath) ?>">Dashboard</a><?php endif; ?>
    </nav>
</header>
<main class="container">
    <?php if ($flash): ?><div class="notice <?= e($flash['type']) ?>"><?= e($flash['message']) ?></div><?php endif; ?>
    <?= $content ?>
</main>
<footer class="site-footer"><span>Powered by a simple, database-free blog.</span><a href="<?= e($adminPath) ?>">Admin</a></footer>
</body>
</html><?php
}

function page_title(string $title, string $eyebrow = ''): string
{
    return '<div class="page-heading">' . ($eyebrow !== '' ? '<p class="eyebrow">' . e($eyebrow) . '</p>' : '') .
        '<h1>' . e($title) . '</h1></div>';
}

function form_input(string $label, string $name, string $value = '', string $type = 'text', bool $required = false): string
{
    return '<label>' . e($label) . '<input type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '"' .
        ($required ? ' required' : '') . '></label>';
}

function content_form(string $kind, array $item = []): string
{
    $kindLabel = $kind === 'post' ? 'Post' : 'Page';
    $adminPath = '/' . trim((string) read_store()['settings']['admin_path'], '/');
    $action = $adminPath . '/' . ($item ? 'save-' : 'create-') . $kind;
    $statuses = ['draft' => 'Draft', 'published' => 'Published'];
    $html = '<form method="post" action="' . e($action) . '" class="stack">' . csrf_field();
    if (!empty($item['slug'])) {
        $html .= '<input type="hidden" name="slug" value="' . e($item['slug']) . '">';
    }
    $html .= form_input('Title', 'title', $item['title'] ?? '', 'text', true);
    $html .= '<label>Content<textarea name="body" rows="10" required>' . e($item['body'] ?? '') . '</textarea></label>';
    $html .= '<label>Visibility<select name="privacy">';
    foreach (['public' => 'Public', 'members' => 'Members only', 'unlisted' => 'Unlisted (link only)'] as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . (($item['privacy'] ?? 'public') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $html .= '</select></label><label>Status<select name="status">';
    foreach ($statuses as $value => $label) {
        $html .= '<option value="' . e($value) . '"' . (($item['status'] ?? 'draft') === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $html .= '</select></label>';
    if ($kind === 'page') {
        $html .= '<label>Contact form<select name="form_id"><option value="">No form</option>';
        foreach (read_store()['forms'] as $form) {
            $selected = ($item['form_id'] ?? '') === $form['id'] ? ' selected' : '';
            $html .= '<option value="' . e($form['id']) . '"' . $selected . '>' . e($form['title']) . '</option>';
        }
        $html .= '</select></label>';
    }
    $html .= '<button class="button" type="submit">' . ($item ? 'Save ' : 'Create ') . e($kindLabel) . '</button></form>';
    return $html;
}

function admin_link(string $href, string $title, string $description): string
{
    return '<a class="admin-card" href="' . e($href) . '"><strong>' . e($title) . '</strong><span>' . e($description) . '</span><b>→</b></a>';
}

function handle_form_action(array &$store, string $path): void
{
    if ($path === '/register') {
        if (!$store['settings']['registration_open']) {
            flash('Registration is currently closed.', 'error');
            redirect('/register');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
            flash('Enter a name, valid email, and password with at least 10 characters.', 'error');
            redirect('/register');
        }
        $exists = false;
        foreach ($store['users'] as $user) {
            if ($user['email'] === $email) {
                $exists = true;
                break;
            }
        }
        if ($exists) {
            flash('An account with that email already exists.', 'error');
            redirect('/register');
        }
        $store['users'][] = [
            'id' => bin2hex(random_bytes(8)), 'name' => $name, 'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'member',
            'created_at' => date(DATE_ATOM),
        ];
        flash('Your account is ready. You can now sign in.');
        redirect('/login');
    }

    if ($path === '/login') {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        foreach ($store['users'] as $user) {
            if ($user['email'] === $email && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];
                flash('Welcome back, ' . $user['name'] . '.');
                redirect(is_admin() ? '/' . trim((string) $store['settings']['admin_path'], '/') : '/');
            }
        }
        flash('Email or password is incorrect.', 'error');
        redirect('/login');
    }

    if (str_starts_with($path, '/admin/')) {
        require_admin();
        $action = substr($path, strlen('/admin/'));
        if (in_array($action, ['create-post', 'save-post', 'create-page', 'save-page'], true)) {
            $kind = str_contains($action, 'post') ? 'posts' : 'pages';
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            if ($title === '' || $body === '') {
                flash('Title and content are required.', 'error');
                redirect('/admin/' . ($kind === 'posts' ? 'new-post' : 'new-page'));
            }
            $oldSlug = trim((string) ($_POST['slug'] ?? ''));
            if ($oldSlug !== '' && isset($store[$kind][$oldSlug])) {
                $item = $store[$kind][$oldSlug];
                unset($store[$kind][$oldSlug]);
            } else {
                $item = ['created_at' => date(DATE_ATOM)];
            }
            $slug = unique_slug($store[$kind], $title, $oldSlug ?: null);
            $item = array_merge($item, [
                'id' => $item['id'] ?? bin2hex(random_bytes(8)),
                'slug' => $slug, 'title' => $title, 'body' => $body,
                'privacy' => in_array($_POST['privacy'] ?? '', ['public', 'members', 'unlisted'], true) ? $_POST['privacy'] : 'public',
                'status' => ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft',
                'updated_at' => date(DATE_ATOM),
            ]);
            if ($kind === 'pages') {
                $validFormIds = array_column($store['forms'], 'id');
                $item['form_id'] = in_array($_POST['form_id'] ?? '', $validFormIds, true) ? (string) $_POST['form_id'] : '';
            }
            $store[$kind][$slug] = $item;
            flash(ucfirst(substr($kind, 0, -1)) . ' saved.');
            redirect('/admin/' . $kind);
        }

        if (in_array($action, ['delete-post', 'delete-page'], true)) {
            $kind = $action === 'delete-post' ? 'posts' : 'pages';
            $slug = (string) ($_POST['slug'] ?? '');
            unset($store[$kind][$slug]);
            flash(ucfirst(substr($kind, 0, -1)) . ' deleted.');
            redirect('/admin/' . $kind);
        }

        if ($action === 'create-poll') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $postSlug = (string) ($_POST['post_slug'] ?? '');
            $options = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($_POST['options'] ?? '')) ?: [])));
            if ($title === '' || !isset($store['posts'][$postSlug]) || count($options) < 2 || count($options) > 20) {
                flash('Choose a post and provide a title and 2–20 options.', 'error');
                redirect('/admin/polls');
            }
            $store['polls'][] = ['id' => bin2hex(random_bytes(8)), 'title' => $title, 'post_slug' => $postSlug,
                'options' => $options, 'votes' => array_fill(0, count($options), 0), 'voters' => [], 'created_at' => date(DATE_ATOM)];
            flash('Poll created and attached to the post.');
            redirect('/admin/polls');
        }

        if ($action === 'delete-poll') {
            $id = (string) ($_POST['id'] ?? '');
            $store['polls'] = array_values(array_filter($store['polls'], static fn(array $poll): bool => $poll['id'] !== $id));
            flash('Poll deleted.');
            redirect('/admin/polls');
        }

        if ($action === 'create-form') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $fields = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($_POST['fields'] ?? '')) ?: [])));
            if ($title === '' || count($fields) < 1 || count($fields) > 20) {
                flash('Provide a form title and 1–20 field names.', 'error');
                redirect('/admin/forms');
            }
            $store['forms'][] = ['id' => bin2hex(random_bytes(8)), 'title' => $title, 'fields' => $fields, 'created_at' => date(DATE_ATOM)];
            flash('Contact form created. You can attach it while editing a page.');
            redirect('/admin/forms');
        }

        if ($action === 'delete-form') {
            $id = (string) ($_POST['id'] ?? '');
            $store['forms'] = array_values(array_filter($store['forms'], static fn(array $form): bool => $form['id'] !== $id));
            foreach ($store['pages'] as &$page) {
                if (($page['form_id'] ?? '') === $id) {
                    $page['form_id'] = '';
                }
            }
            unset($page);
            flash('Form deleted.');
            redirect('/admin/forms');
        }

        if ($action === 'comment-action') {
            $id = (string) ($_POST['id'] ?? '');
            $decision = (string) ($_POST['decision'] ?? '');
            foreach ($store['comments'] as &$comment) {
                if ($comment['id'] === $id) {
                    if ($decision === 'delete') {
                        $comment['deleted'] = true;
                    } else {
                        $comment['approved'] = $decision === 'approve';
                    }
                    break;
                }
            }
            unset($comment);
            flash('Comment updated.');
            redirect('/admin/comments');
        }

        if ($action === 'message-action') {
            $id = (string) ($_POST['id'] ?? '');
            foreach ($store['messages'] as &$message) {
                if ($message['id'] === $id) {
                    if (($_POST['decision'] ?? '') === 'delete') {
                        $message['deleted'] = true;
                    } else {
                        $message['read'] = true;
                    }
                }
            }
            unset($message);
            flash('Inbox updated.');
            redirect('/admin/inbox');
        }

        if ($action === 'save-settings') {
            $adminPath = trim((string) ($_POST['admin_path'] ?? ''), '/');
            $adminPath = preg_replace('/[^a-zA-Z0-9_-]/', '', $adminPath) ?? '';
            if ($adminPath === '' || in_array($adminPath, ['login', 'logout', 'register', 'post', 'page', 'setup', 'assets', 'comments', 'poll', 'forms', 'storage'], true)) {
                flash('Choose an available admin URL path.', 'error');
                redirect('/admin/settings');
            }
            $store['settings'] = array_merge($store['settings'], [
                'title' => trim((string) ($_POST['title'] ?? '')) ?: 'My Flat-file Blog',
                'tagline' => trim((string) ($_POST['tagline'] ?? '')),
                'admin_path' => $adminPath,
                'registration_open' => isset($_POST['registration_open']),
                'comments_enabled' => isset($_POST['comments_enabled']),
                'comments_moderated' => isset($_POST['comments_moderated']),
                'comments_visibility' => ($_POST['comments_visibility'] ?? '') === 'members' ? 'members' : 'all',
            ]);
            flash('Site settings saved.');
            redirect('/' . $adminPath . '/settings');
        }
    }

    if ($path === '/comments/create') {
        $postSlug = (string) ($_POST['post_slug'] ?? '');
        $name = trim((string) ($_POST['name'] ?? current_user()['name'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        if (!$store['settings']['comments_enabled'] || !isset($store['posts'][$postSlug]) ||
            ($store['posts'][$postSlug]['status'] ?? '') !== 'published' ||
            (($store['posts'][$postSlug]['privacy'] ?? '') === 'members' && !current_user()) || $body === '' ||
            (!$store['settings']['comments_visibility'] || $store['settings']['comments_visibility'] === 'members') && !current_user()) {
            flash('Please sign in and try again.', 'error');
            redirect('/post/' . rawurlencode($postSlug));
        }
        $store['comments'][] = [
            'id' => bin2hex(random_bytes(8)), 'post_slug' => $postSlug, 'name' => $name ?: 'Guest',
            'user_id' => current_user()['id'] ?? '', 'body' => $body,
            'approved' => !$store['settings']['comments_moderated'], 'deleted' => false,
            'created_at' => date(DATE_ATOM),
        ];
        flash($store['settings']['comments_moderated'] ? 'Your comment was submitted for approval.' : 'Your comment has been posted.');
        redirect('/post/' . rawurlencode($postSlug) . '#comments');
    }

    if ($path === '/poll/vote') {
        $id = (string) ($_POST['poll_id'] ?? '');
        $choice = filter_var($_POST['choice'] ?? null, FILTER_VALIDATE_INT);
        foreach ($store['polls'] as &$poll) {
            if ($poll['id'] !== $id) {
                continue;
            }
            $voter = current_user()['id'] ?? hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            if ($choice === false || !isset($poll['options'][$choice]) || in_array($voter, $poll['voters'], true)) {
                flash('Your vote could not be recorded.', 'error');
            } else {
                $poll['votes'][$choice]++;
                $poll['voters'][] = $voter;
                flash('Thanks for voting.');
            }
            break;
        }
        unset($poll);
        redirect('/post/' . rawurlencode((string) ($_POST['post_slug'] ?? '')));
    }

    if ($path === '/forms/submit') {
        $formId = (string) ($_POST['form_id'] ?? '');
        $form = null;
        foreach ($store['forms'] as $candidate) {
            if ($candidate['id'] === $formId) {
                $form = $candidate;
                break;
            }
        }
        $pageSlug = (string) ($_POST['page_slug'] ?? '');
        if (!$form || !isset($store['pages'][$pageSlug]) ||
            ($store['pages'][$pageSlug]['status'] ?? '') !== 'published' ||
            ($store['pages'][$pageSlug]['form_id'] ?? '') !== $formId ||
            (($store['pages'][$pageSlug]['privacy'] ?? '') === 'members' && !current_user())) {
            flash('This form is not available.', 'error');
            redirect('/');
        }
        $values = [];
        foreach ($form['fields'] as $index => $field) {
            $value = trim((string) ($_POST['field_' . $index] ?? ''));
            if ($value === '') {
                flash('Please complete every form field.', 'error');
                redirect('/page/' . rawurlencode($pageSlug));
            }
            $values[$field] = text_limit($value, 5000);
        }
        if (!$values) {
            flash('Please fill in at least one field.', 'error');
            redirect('/page/' . rawurlencode((string) ($_POST['page_slug'] ?? '')));
        }
        $store['messages'][] = ['id' => bin2hex(random_bytes(8)), 'form_title' => $form['title'],
            'page_slug' => (string) ($_POST['page_slug'] ?? ''), 'values' => $values, 'read' => false,
            'deleted' => false, 'created_at' => date(DATE_ATOM)];
        flash('Your message has been sent.');
        redirect('/page/' . rawurlencode((string) ($_POST['page_slug'] ?? '')));
    }
}

$store = read_store();
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$path = '/' . trim(rawurldecode($path), '/');
if ($path === '//') {
    $path = '/';
}
$adminPath = '/' . trim((string) $store['settings']['admin_path'], '/');
$adminRoute = $path === $adminPath ? '/admin' : (str_starts_with($path, $adminPath . '/') ? '/admin' . substr($path, strlen($adminPath)) : null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path !== '/setup') {
    verify_csrf();
    try {
        $result = update_store(function (array &$data) use ($path, $adminRoute): mixed {
            handle_form_action($data, $adminRoute ?? $path);
            return null;
        });
        if (is_string($result)) {
            redirect($result);
        }
    } catch (Throwable $exception) {
        flash($exception->getMessage(), 'error');
        redirect('/');
    }
}

if ($path === '/logout') {
    $_SESSION = [];
    session_regenerate_id(true);
    flash('You have signed out.');
    redirect('/');
}

if ($path === '/setup' && count($store['users']) === 0) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12) {
            flash('Enter a name, valid email, and administrator password with at least 12 characters.', 'error');
            redirect('/setup');
        }
        update_store(static function (array &$data) use ($name, $email, $password): void {
            if (count($data['users']) !== 0) {
                return;
            }
            $data['users'][] = ['id' => bin2hex(random_bytes(8)), 'name' => $name, 'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'admin', 'created_at' => date(DATE_ATOM)];
        });
        flash('Administrator created. Sign in to continue.');
        redirect('/login');
    }
    ob_start(); ?>
    <?= page_title('Create your administrator', 'First-time setup') ?>
    <p>Set up the first account. This page closes automatically once an account exists.</p>
    <form method="post" class="panel stack"><?= csrf_field() ?>
        <?= form_input('Name', 'name', '', 'text', true) ?><?= form_input('Email', 'email', '', 'email', true) ?>
        <?= form_input('Password (at least 12 characters)', 'password', '', 'password', true) ?>
        <button class="button" type="submit">Create administrator</button>
    </form>
    <?php render('First-time setup', ob_get_clean(), $store);
    exit;
}

if ($path === '/login' || $path === '/register') {
    $isLogin = $path === '/login';
    ob_start();
    echo page_title($isLogin ? 'Welcome back' : 'Create an account', 'Member access');
    ?><form method="post" class="panel stack"><?= csrf_field() ?>
        <?php if (!$isLogin): ?><?= form_input('Name', 'name', '', 'text', true) ?><?php endif; ?>
        <?= form_input('Email', 'email', '', 'email', true) ?><?= form_input('Password', 'password', '', 'password', true) ?>
        <button class="button" type="submit"><?= $isLogin ? 'Sign in' : 'Register' ?></button>
        <?php if ($isLogin && $store['settings']['registration_open']): ?><p class="muted">New here? <a href="/register">Create an account</a>.</p><?php endif; ?>
    </form><?php
    render($isLogin ? 'Sign in' : 'Register', ob_get_clean(), $store);
    exit;
}

if ($adminRoute !== null) {
    require_admin();
    $adminSubpath = trim(substr($adminRoute, strlen('/admin')), '/');
    ob_start();
    echo page_title('Dashboard', 'Administration');
    ?><div class="admin-grid">
        <?= admin_link($adminPath . '/posts', 'Posts', count($store['posts']) . ' posts') ?>
        <?= admin_link($adminPath . '/pages', 'Pages', count($store['pages']) . ' pages') ?>
        <?= admin_link($adminPath . '/comments', 'Comments', count(array_filter($store['comments'], static fn(array $c): bool => empty($c['approved']) && empty($c['deleted']))) . ' awaiting approval') ?>
        <?= admin_link($adminPath . '/polls', 'Polls', count($store['polls']) . ' polls') ?>
        <?= admin_link($adminPath . '/forms', 'Contact forms', count($store['forms']) . ' forms') ?>
        <?= admin_link($adminPath . '/inbox', 'Inbox', count(array_filter($store['messages'], static fn(array $m): bool => empty($m['read']) && empty($m['deleted']))) . ' unread messages') ?>
        <?= admin_link($adminPath . '/settings', 'Settings', 'Site, membership, comments, and admin URL') ?>
        <?= admin_link('/', 'View site', 'Open the public site') ?>
    </div>
    <?php
    if ($adminSubpath === 'posts' || $adminSubpath === 'pages') {
        $kind = $adminSubpath;
        $label = ucfirst($kind);
        echo '<div class="section-heading"><h2>' . e($label) . '</h2><a class="button small" href="' . e($adminPath . '/new-' . substr($kind, 0, -1)) . '">New ' . e(substr($kind, 0, -1)) . '</a></div>';
        echo '<div class="table-wrap"><table><thead><tr><th>Title</th><th>Status</th><th>Visibility</th><th>Actions</th></tr></thead><tbody>';
        foreach ($store[$kind] as $item) {
            echo '<tr><td>' . e($item['title']) . '</td><td>' . e($item['status']) . '</td><td>' . e($item['privacy']) . '</td><td><a href="' . e('/' . substr($kind, 0, -1) . '/' . $item['slug']) . '">View</a> · <a href="' . e($adminPath . '/edit-' . substr($kind, 0, -1) . '?slug=' . rawurlencode($item['slug'])) . '">Edit</a> <form class="inline" method="post" action="' . e($adminPath . '/delete-' . substr($kind, 0, -1)) . '">' . csrf_field() . '<input type="hidden" name="slug" value="' . e($item['slug']) . '"><button class="link danger" type="submit">Delete</button></form></td></tr>';
        }
        if (!$store[$kind]) echo '<tr><td colspan="4" class="muted">Nothing here yet.</td></tr>';
        echo '</tbody></table></div>';
    } elseif (in_array($adminSubpath, ['new-post', 'new-page'], true)) {
        $kind = str_ends_with($adminSubpath, 'post') ? 'post' : 'page';
        echo '<section class="panel"><h2>Create ' . e($kind) . '</h2>' . content_form($kind) . '</section>';
    } elseif (str_starts_with($adminSubpath, 'edit-')) {
        $kind = substr($adminSubpath, 5) === 'post' ? 'posts' : 'pages';
        $slug = (string) ($_GET['slug'] ?? '');
        if (isset($store[$kind][$slug])) {
            echo '<section class="panel"><h2>Edit ' . e(substr($kind, 0, -1)) . '</h2>' . content_form(substr($kind, 0, -1), $store[$kind][$slug]) . '</section>';
        } else {
            echo '<p>Content not found.</p>';
        }
    } elseif ($adminSubpath === 'comments') {
        echo '<h2>Comment moderation</h2><div class="stack">';
        foreach (array_reverse($store['comments']) as $comment) {
            if (!empty($comment['deleted'])) continue;
            echo '<article class="panel"><div class="section-heading"><strong>' . e($comment['name']) . '</strong><span class="muted">' . e($comment['created_at']) . '</span></div><p>' . nl2br(e($comment['body'])) . '</p><p class="muted">On <a href="/post/' . e($comment['post_slug']) . '">' . e($store['posts'][$comment['post_slug']]['title'] ?? $comment['post_slug']) . '</a> · ' . (empty($comment['approved']) ? 'Pending' : 'Approved') . '</p><form method="post" action="' . e($adminPath . '/comment-action') . '" class="inline-actions">' . csrf_field() . '<input type="hidden" name="id" value="' . e($comment['id']) . '"><button class="button small" name="decision" value="approve">Approve</button><button class="button secondary small" name="decision" value="hide">Hide</button><button class="button danger small" name="decision" value="delete">Delete</button></form></article>';
        }
        echo '</div>';
    } elseif ($adminSubpath === 'polls') {
        echo '<section class="panel"><h2>Create a poll</h2><form method="post" action="' . e($adminPath . '/create-poll') . '" class="stack">' . csrf_field() . form_input('Question', 'title', '', 'text', true) . '<label>Attach to post<select name="post_slug" required><option value="">Choose a post</option>';
        foreach ($store['posts'] as $post) echo '<option value="' . e($post['slug']) . '">' . e($post['title']) . '</option>';
        echo '</select></label><label>Options (one per line)<textarea name="options" rows="5" required></textarea></label><button class="button" type="submit">Create poll</button></form></section><h2>Existing polls</h2>';
        foreach ($store['polls'] as $poll) echo '<article class="panel section-heading"><span><strong>' . e($poll['title']) . '</strong> · ' . e($store['posts'][$poll['post_slug']]['title'] ?? 'Missing post') . '</span><form method="post" action="' . e($adminPath . '/delete-poll') . '">' . csrf_field() . '<input type="hidden" name="id" value="' . e($poll['id']) . '"><button class="link danger">Delete</button></form></article>';
    } elseif ($adminSubpath === 'forms') {
        echo '<section class="panel"><h2>Create a contact form</h2><form method="post" action="' . e($adminPath . '/create-form') . '" class="stack">' . csrf_field() . form_input('Form title', 'title', '', 'text', true) . '<label>Fields (one per line)<textarea name="fields" rows="5" placeholder="Your name&#10;Your email&#10;Message" required></textarea></label><button class="button" type="submit">Create form</button></form></section><h2>Existing forms</h2>';
        foreach ($store['forms'] as $form) echo '<article class="panel section-heading"><span><strong>' . e($form['title']) . '</strong><span class="muted"> · ' . e(implode(', ', $form['fields'])) . '</span></span><form method="post" action="' . e($adminPath . '/delete-form') . '">' . csrf_field() . '<input type="hidden" name="id" value="' . e($form['id']) . '"><button class="link danger">Delete</button></form></article>';
    } elseif ($adminSubpath === 'inbox') {
        echo '<h2>Inbox</h2><div class="stack">';
        foreach (array_reverse($store['messages']) as $message) {
            if (!empty($message['deleted'])) continue;
            echo '<article class="panel"><div class="section-heading"><strong>' . e($message['form_title']) . '</strong><span class="muted">' . e($message['created_at']) . (!empty($message['read']) ? ' · Read' : ' · New') . '</span></div><p class="muted">Page: ' . e($store['pages'][$message['page_slug']]['title'] ?? $message['page_slug']) . '</p>';
            foreach ($message['values'] as $field => $value) echo '<p><strong>' . e($field) . ':</strong><br>' . nl2br(e($value)) . '</p>';
            echo '<form method="post" action="' . e($adminPath . '/message-action') . '" class="inline-actions">' . csrf_field() . '<input type="hidden" name="id" value="' . e($message['id']) . '"><button class="button small" name="decision" value="read">Mark read</button><button class="button danger small" name="decision" value="delete">Delete</button></form></article>';
        }
        echo '</div>';
    } elseif ($adminSubpath === 'settings') {
        echo '<section class="panel"><h2>Site settings</h2><form method="post" action="' . e($adminPath . '/save-settings') . '" class="stack">' . csrf_field();
        echo form_input('Site title', 'title', $store['settings']['title'], 'text', true) . form_input('Tagline', 'tagline', $store['settings']['tagline']) . form_input('Admin URL path (without slashes)', 'admin_path', $store['settings']['admin_path'], 'text', true);
        echo '<label class="check"><input type="checkbox" name="registration_open" value="1"' . ($store['settings']['registration_open'] ? ' checked' : '') . '> Allow registration</label><label class="check"><input type="checkbox" name="comments_enabled" value="1"' . ($store['settings']['comments_enabled'] ? ' checked' : '') . '> Enable comments</label><label class="check"><input type="checkbox" name="comments_moderated" value="1"' . ($store['settings']['comments_moderated'] ? ' checked' : '') . '> Require approval for comments</label><label>Who can view comments?<select name="comments_visibility"><option value="all"' . ($store['settings']['comments_visibility'] === 'all' ? ' selected' : '') . '>Everyone</option><option value="members"' . ($store['settings']['comments_visibility'] === 'members' ? ' selected' : '') . '>Members only</option></select></label><button class="button" type="submit">Save settings</button></form><p class="muted">Comment submission can be configured independently from comment visibility.</p></section>';
    }
    render('Dashboard', ob_get_clean(), $store);
    exit;
}

if ($path === '/') {
    ob_start();
    echo '<section class="hero"><p class="eyebrow">Stories worth sharing</p><h1>' . e($store['settings']['title']) . '</h1><p>' . e($store['settings']['tagline']) . '</p></section>';
    $posts = array_filter($store['posts'], static fn(array $post): bool =>
        ($post['status'] ?? '') === 'published' && (($post['privacy'] ?? 'public') === 'public' || (($post['privacy'] ?? '') === 'members' && current_user()))
    );
    uasort($posts, static fn(array $a, array $b): int => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
    if (!$posts) echo '<section class="empty-state"><h2>No posts yet</h2><p>Check back soon for something new.</p></section>';
    echo '<div class="post-grid">';
    foreach ($posts as $post) {
        echo '<article class="post-card"><p class="eyebrow">' . e(ucfirst($post['privacy'])) . ' · ' . e(substr($post['updated_at'] ?? '', 0, 10)) . '</p><h2><a href="/post/' . e($post['slug']) . '">' . e($post['title']) . '</a></h2><p>' . e(text_limit(strip_tags($post['body']), 180)) . (text_length(strip_tags($post['body'])) > 180 ? '…' : '') . '</p><a class="read-more" href="/post/' . e($post['slug']) . '">Read story <span>→</span></a></article>';
    }
    echo '</div>';
    render('Home', ob_get_clean(), $store);
    exit;
}

if (preg_match('#^/(post|page)/([^/]+)$#', $path, $matches)) {
    $kind = $matches[1] === 'post' ? 'posts' : 'pages';
    $slug = $matches[2];
    $item = $store[$kind][$slug] ?? null;
    if (!$item || ($item['status'] ?? '') !== 'published' ||
        (($item['privacy'] ?? 'public') === 'members' && !current_user())) {
        http_response_code($item && ($item['privacy'] ?? '') === 'members' ? 403 : 404);
        if ($item && ($item['privacy'] ?? '') === 'members' && !current_user()) {
            render('Members only', page_title('Members only', 'Private content') . '<p>Please <a href="/login">sign in</a> to view this content.</p>', $store);
        } else {
            render('Not found', page_title('Not found') . '<p>This content is not available.</p>', $store);
        }
        exit;
    }
    ob_start();
    echo '<article class="article"><p class="eyebrow">' . e($kind === 'posts' ? 'Article' : 'Page') . ' · ' . e(substr($item['updated_at'] ?? '', 0, 10)) . ($item['privacy'] !== 'public' ? ' · ' . e($item['privacy']) : '') . '</p><h1>' . e($item['title']) . '</h1><div class="article-body">' . nl2br(e($item['body'])) . '</div></article>';
    if ($kind === 'posts') {
        foreach ($store['polls'] as $poll) {
            if ($poll['post_slug'] !== $slug) continue;
            $total = array_sum($poll['votes']);
            echo '<section class="panel poll"><h2>' . e($poll['title']) . '</h2><form method="post" action="/poll/vote" class="stack">' . csrf_field() . '<input type="hidden" name="poll_id" value="' . e($poll['id']) . '"><input type="hidden" name="post_slug" value="' . e($slug) . '">';
            foreach ($poll['options'] as $index => $option) {
                $percent = $total ? (int) round($poll['votes'][$index] / $total * 100) : 0;
                echo '<label class="poll-option"><input type="radio" name="choice" value="' . e($index) . '" required> ' . e($option) . ($total ? '<span class="poll-result">' . $percent . '% (' . (int) $poll['votes'][$index] . ')</span>' : '') . '</label>';
            }
            echo '<button class="button small" type="submit">Vote</button></form><p class="muted">' . $total . ' vote' . ($total === 1 ? '' : 's') . '</p></section>';
        }
        if ($store['settings']['comments_enabled']) {
            $commentsAllowed = $store['settings']['comments_visibility'] !== 'members' || current_user();
            echo '<section id="comments" class="comments"><h2>Comments</h2>';
            foreach ($store['comments'] as $comment) {
                if ($comment['post_slug'] === $slug && !empty($comment['approved']) && empty($comment['deleted'])) {
                    echo '<article class="comment"><strong>' . e($comment['name']) . '</strong><span class="muted">' . e(substr($comment['created_at'], 0, 10)) . '</span><p>' . nl2br(e($comment['body'])) . '</p></article>';
                }
            }
            if ($commentsAllowed) {
                echo '<form method="post" action="/comments/create" class="panel stack">' . csrf_field() . '<input type="hidden" name="post_slug" value="' . e($slug) . '">';
                if (!current_user()) echo form_input('Your name', 'name');
                echo '<label>Your comment<textarea name="body" rows="4" required></textarea></label><button class="button" type="submit">Send comment</button></form>';
            } else {
                echo '<p>Sign in to view and write comments. <a href="/login">Sign in</a></p>';
            }
            echo '</section>';
        }
    } elseif (!empty($item['form_id'])) {
        foreach ($store['forms'] as $form) {
            if ($form['id'] !== $item['form_id']) continue;
            echo '<section class="panel"><h2>' . e($form['title']) . '</h2><form method="post" action="/forms/submit" class="stack">' . csrf_field() . '<input type="hidden" name="form_id" value="' . e($form['id']) . '"><input type="hidden" name="page_slug" value="' . e($slug) . '">';
            foreach ($form['fields'] as $index => $field) echo '<label>' . e($field) . '<textarea name="field_' . e($index) . '" rows="3" required></textarea></label>';
            echo '<button class="button" type="submit">Send message</button></form></section>';
        }
    }
    render($item['title'], ob_get_clean(), $store);
    exit;
}

http_response_code(404);
render('Not found', page_title('Not found') . '<p>This page does not exist. <a href="/">Return home</a>.</p>', $store);
