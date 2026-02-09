<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once(__DIR__ . "/config.php");
require_once(__DIR__ . "/safe_html.php");
require_once(__DIR__ . "/embed_helper.php");
require_once(__DIR__ . "/forum_auth.php");
require_once(__DIR__ . "/forum_user_lookup.php");
$hasForumAdmin = forum_has_chat_admin();

// Create connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Open Graph / Discord / link preview: fetch post when postid is in URL
$og_title = null;
$og_description = null;
$og_url = null;
$post_id_param = isset($_GET['postid']) ? max(0, (int) $_GET['postid']) : 0;
if ($post_id_param > 0) {
    $stmt = $conn->prepare("SELECT ft.subject, ft.author, ft.date FROM forumthreads ft WHERE ft.id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $post_id_param);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && $res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $og_title = trim($row['subject']) !== '' ? $row['subject'] : 'Forum post #' . $post_id_param;
            $og_title = $og_title . ' — ' . htmlspecialchars($row['author'], ENT_QUOTES, 'UTF-8');
            $body_stmt = $conn->prepare("SELECT body FROM forumbodies WHERE id = ?");
            if ($body_stmt) {
                $body_stmt->bind_param("i", $post_id_param);
                $body_stmt->execute();
                $body_res = $body_stmt->get_result();
                if ($body_res && $body_res->num_rows > 0) {
                    $raw = $body_res->fetch_assoc()['body'];
                    $plain = trim(strip_tags($raw));
                    $plain = preg_replace('/\s+/', ' ', $plain);
                    $og_description = mb_substr($plain, 0, 200);
                    if (mb_strlen($plain) > 200) $og_description .= '…';
                }
            }
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'psistorm.com';
            $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : ('/fsl/forum/index.php?postid=' . $post_id_param);
            $og_url = $scheme . '://' . $host . $uri;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" href="../images/favicon.png" type="image/png">
<title><?php echo $og_title !== null ? htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8') . ' — Forum' : 'Forum'; ?></title>
<?php if ($og_title !== null && $og_url !== null): ?>
<meta property="og:type" content="article">
<meta property="og:title" content="<?php echo htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($og_description !== null && $og_description !== ''): ?>
<meta property="og:description" content="<?php echo htmlspecialchars($og_description, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>
<meta property="og:url" content="<?php echo htmlspecialchars($og_url, ENT_QUOTES, 'UTF-8'); ?>">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?php echo htmlspecialchars($og_title, ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($og_description !== null && $og_description !== ''): ?>
<meta name="twitter:description" content="<?php echo htmlspecialchars($og_description, ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>
<?php endif; ?>
<link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
<?php include __DIR__ . '/forum_theme_init.php'; ?>
</head>
<body>

<?php
function renderPagination($page, $totalPages, $suffix = '', $forum = 'all', $sort = 'topics') {
    if ($totalPages < 2) return '';
    $q = ($forum === 'all' || ($forum !== 1 && $forum > 1)) ? '&forum=' . $forum : '';
    if ($sort === 'replies') $q .= '&sort=replies';
    $currentPage = max(1, min($page, $totalPages));
    $prevPage = $currentPage > 1 ? $currentPage - 1 : null;
    $nextPage = $currentPage < $totalPages ? $currentPage + 1 : null;
    $idPage = 'pagination-page' . $suffix;
    $out = '<nav class="pagination" aria-label="Thread list pages" data-page="' . (int)$currentPage . '" data-total="' . (int)$totalPages . '" data-forum="' . htmlspecialchars($forum, ENT_QUOTES, 'UTF-8') . '" data-sort="' . htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') . '">';
    $out .= '<span class="pagination-label">Page ' . (int)$currentPage . ' of ' . (int)$totalPages . '</span>';
    $out .= '<div class="pagination-controls">';
    if ($currentPage <= 1) {
        $out .= '<span class="pagination-btn pagination-btn--disabled" title="First page" aria-disabled="true">««</span>';
    } else {
        $out .= '<a href="index.php?page=1' . $q . '" class="pagination-btn" data-page="1" title="First page">««</a>';
    }
    if ($prevPage) {
        $out .= '<a href="index.php?page=' . $prevPage . $q . '" class="pagination-btn" data-page="' . $prevPage . '" title="Previous page">« Prev</a>';
    } else {
        $out .= '<span class="pagination-btn pagination-btn--disabled" aria-disabled="true">« Prev</span>';
    }
    $out .= '<form class="pagination-go" method="get" action="index.php" data-pagination-form="">';
    if ($forum !== 1 && $forum !== '') $out .= '<input type="hidden" name="forum" value="' . htmlspecialchars($forum, ENT_QUOTES, 'UTF-8') . '">';
    if ($sort === 'replies') $out .= '<input type="hidden" name="sort" value="replies">';
    $out .= '<label for="' . $idPage . '">Go to</label>';
    $out .= '<input type="number" id="' . $idPage . '" name="page" class="pagination-page-input" min="1" max="' . (int)$totalPages . '" value="' . (int)$currentPage . '" aria-label="Page number">';
    $out .= '<button type="submit" class="pagination-go-btn">Go</button>';
    $out .= '</form>';
    if ($nextPage) {
        $out .= '<a href="index.php?page=' . $nextPage . $q . '" class="pagination-btn" data-page="' . $nextPage . '" title="Next page">Next »</a>';
    } else {
        $out .= '<span class="pagination-btn pagination-btn--disabled" aria-disabled="true">Next »</span>';
    }
    if ($currentPage >= $totalPages) {
        $out .= '<span class="pagination-btn pagination-btn--disabled" title="Last page" aria-disabled="true">»»</span>';
    } else {
        $out .= '<a href="index.php?page=' . (int)$totalPages . $q . '" class="pagination-btn" data-page="' . (int)$totalPages . '" title="Last page">»»</a>';
    }
    $out .= '</div></nav>';
    return $out;
}

function displayThreads($page, $limit, $ajax = false, $forum = 'all', $sort = 'topics') {
    global $conn;

    $current_forum = $forum;
    $show_all = ($forum === 'all');
    if (!$show_all) {
        $forum = (int) $forum;
        if ($forum < 1) $forum = 1;
        $current_forum = $forum;
    }

    $order_by = ($sort === 'replies')
        ? '(SELECT COALESCE(MAX(r.date), ft.date) FROM forumthreads r WHERE r.parent = ft.id) DESC'
        : 'ft.date DESC';
    $order_by_single = ($sort === 'replies')
        ? '(SELECT COALESCE(MAX(r.date), forumthreads.date) FROM forumthreads r WHERE r.parent = forumthreads.id) DESC'
        : 'date DESC';

    $offset = ($page - 1) * $limit;
    $has_site_user_id = true;
    if ($show_all) {
        $sql = "SELECT ft.id, ft.date, ft.author, ft.subject, ft.forum, ft.site_user_id, ft.hits, f.title AS forum_title, (SELECT COUNT(*) FROM forumthreads r WHERE r.mainthread = ft.id AND r.parent != -1) AS reply_count FROM forumthreads ft LEFT JOIN forums f ON ft.forum = f.id WHERE ft.parent = -1 ORDER BY {$order_by} LIMIT ?, ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $sql = "SELECT ft.id, ft.date, ft.author, ft.subject, ft.forum, f.title AS forum_title, (SELECT COUNT(*) FROM forumthreads r WHERE r.mainthread = ft.id AND r.parent != -1) AS reply_count FROM forumthreads ft LEFT JOIN forums f ON ft.forum = f.id WHERE ft.parent = -1 ORDER BY {$order_by} LIMIT ?, ?";
            $stmt = $conn->prepare($sql);
            $has_site_user_id = false;
        }
        if ($stmt) $stmt->bind_param("ii", $offset, $limit);
        if ($stmt) $stmt->execute();
        $result = $stmt ? $stmt->get_result() : null;
        $stmt_c = $conn->prepare("SELECT COUNT(*) AS total FROM forumthreads WHERE parent = -1");
        $stmt_c->execute();
    } else {
        $sql = "SELECT id, date, author, subject, site_user_id, hits, (SELECT COUNT(*) FROM forumthreads r WHERE r.mainthread = forumthreads.id AND r.parent != -1) AS reply_count FROM forumthreads WHERE parent = -1 AND forum = ? ORDER BY {$order_by_single} LIMIT ?, ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $sql = "SELECT id, date, author, subject, (SELECT COUNT(*) FROM forumthreads r WHERE r.mainthread = forumthreads.id AND r.parent != -1) AS reply_count FROM forumthreads WHERE parent = -1 AND forum = ? ORDER BY {$order_by_single} LIMIT ?, ?";
            $stmt = $conn->prepare($sql);
            $has_site_user_id = false;
        }
        if ($stmt) $stmt->bind_param("iii", $forum, $offset, $limit);
        if ($stmt) $stmt->execute();
        $result = $stmt ? $stmt->get_result() : null;
        $stmt_c = $conn->prepare("SELECT COUNT(*) AS total FROM forumthreads WHERE parent = -1 AND forum = ?");
        $stmt_c->bind_param("i", $forum);
        $stmt_c->execute();
    }
    $totalPosts = $stmt_c->get_result()->fetch_assoc()['total'];
    $totalPages = (int) ceil($totalPosts / $limit);

    if (!$ajax) {
        include("header.php");
        $expand_id = isset($_GET['expand']) ? max(0, (int) $_GET['expand']) : 0;
        $post_id = isset($_GET['postid']) ? max(0, (int) $_GET['postid']) : 0;
        echo '<main class="forum-main" data-initial-page="' . (int)$page . '" data-expand-id="' . $expand_id . '" data-post-id="' . $post_id . '" data-forum="' . htmlspecialchars($current_forum, ENT_QUOTES, 'UTF-8') . '" data-sort="' . htmlspecialchars($sort, ENT_QUOTES, 'UTF-8') . '"><div class="forum-main-inner">';
    }

    if ($result && $result->num_rows > 0) {
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            if (!$has_site_user_id) $row['site_user_id'] = null;
            if (!array_key_exists('hits', $row)) $row['hits'] = 0;
            if (!array_key_exists('reply_count', $row)) $row['reply_count'] = 0;
            $rows[] = $row;
        }
        $user_ids = [];
        $author_by_id = [];
        $avatars_by_author = [];
        foreach ($rows as $r) {
            $v = isset($r['site_user_id']) ? $r['site_user_id'] : null;
            if ($v !== null && $v !== '' && (int) $v >= 0) {
                $uid = (int) $v;
                $user_ids[] = $uid;
                $author_by_id[$uid] = isset($r['author']) ? $r['author'] : '';
                if ($uid === 0 && !empty(trim($r['author'] ?? ''))) {
                    $lookup = forum_lookup_user_by_author($r['author']);
                    if ($lookup !== null && !empty($lookup['avatar_url'])) {
                        $avatars_by_author[trim($r['author'])] = $lookup['avatar_url'];
                    }
                }
            }
        }
        $user_ids = array_unique($user_ids);
        $avatars = forum_get_user_avatars($user_ids, $author_by_id);
        global $basePath;
        $profileBase = (isset($basePath) ? $basePath : '../');
        echo renderPagination($page, $totalPages, '-top', $current_forum, $sort);
        echo '<ul class="thread-list">';
        foreach ($rows as $row) {
            $subj = htmlspecialchars($row['subject'], ENT_QUOTES, 'UTF-8');
            $author = htmlspecialchars($row['author'], ENT_QUOTES, 'UTF-8');
            $date = htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8');
            $tid = (int) $row['id'];
            $sid = isset($row['site_user_id']) && $row['site_user_id'] !== '' && $row['site_user_id'] !== null ? (int) $row['site_user_id'] : null;
            $authorHtml = $author;
            if ($sid !== null) {
                $profileUrl = $profileBase . 'profile.php?username=' . rawurlencode($row['author']);
                $avatarHtml = '';
                $au = null;
                if ($sid === 0 && !empty(trim($row['author'] ?? '')) && isset($avatars_by_author[trim($row['author'])])) {
                    $au = $avatars_by_author[trim($row['author'])];
                } elseif (!empty($avatars[$sid]['avatar_url'])) {
                    $au = $avatars[$sid]['avatar_url'];
                }
                if ($au) {
                    $src = (strpos($au, 'http') === 0) ? $au : ($profileBase . $au);
                    $avatarHtml = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" class="forum-author-avatar">';
                }
                $authorHtml = $avatarHtml . '<a href="' . htmlspecialchars($profileUrl, ENT_QUOTES, 'UTF-8') . '" class="forum-author-link">' . $author . '</a> <span class="forum-registered-badge" title="Registered user">®</span>';
            }
            $forumLabel = $show_all && !empty($row['forum_title']) ? ' <span class="thread-forum-label">' . htmlspecialchars($row['forum_title'], ENT_QUOTES, 'UTF-8') . '</span>' : '';
            $hits = isset($row['hits']) ? (int) $row['hits'] : 0;
            $replyCount = isset($row['reply_count']) ? (int) $row['reply_count'] : 0;
            echo "<li class=\"thread-item\" data-id=\"{$tid}\">";
            echo "<div class=\"thread-item-head\"><button type=\"button\" class=\"thread-expand-btn\" aria-expanded=\"false\" title=\"Expand\">▶</button>";
            echo "<span class=\"thread-link\">{$subj}{$forumLabel}</span>";
            echo "<span class=\"thread-meta\">by {$authorHtml} on {$date} <strong class=\"thread-replies-label\">Replies:</strong> {$replyCount} <span class=\"thread-hits\">hits: {$hits}</span></span></div>";
            echo "<div class=\"thread-inline\" hidden></div></li>";
        }
        echo '</ul>';
        echo renderPagination($page, $totalPages, '-bottom', $current_forum, $sort);
    } else {
        echo '<p class="forum-empty">0 results</p>';
    }

    if (!$ajax) {
        echo '</div></main>';
        echo '<div id="move-modal" class="move-modal" hidden aria-modal="true" role="dialog" aria-labelledby="move-modal-title">';
        echo '<div class="move-modal-backdrop"></div>';
        echo '<div class="move-modal-inner">';
        echo '<h2 id="move-modal-title" class="move-modal-title">Move post</h2>';
        echo '<button type="button" class="move-modal-make-topic">Make this post its own topic</button>';
        echo '<p class="move-modal-or">Or make it a reply to another post:</p>';
        echo '<div class="move-modal-search-row">';
        echo '<input type="text" class="move-modal-search-input" placeholder="Search by subject…" aria-label="Search posts">';
        echo '<label class="move-modal-body-check"><input type="checkbox" class="move-modal-search-body"> Search in body</label>';
        echo '<button type="button" class="move-modal-search-btn">Search</button>';
        echo '</div>';
        echo '<div class="move-modal-results" aria-live="polite"></div>';
        echo '<div class="move-modal-selected" hidden><span class="move-modal-selected-text"></span> <button type="button" class="move-modal-confirm">Confirm move</button> <button type="button" class="move-modal-clear">Clear</button></div>';
        echo '<div class="move-modal-to-forum">';
        echo '<p class="move-modal-or">Or move this post (and its replies) to another forum:</p>';
        echo '<div class="move-modal-forum-row">';
        echo '<select class="move-modal-forum-select" aria-label="Target forum"><option value="">— Select forum —</option></select>';
        echo '<button type="button" class="move-modal-move-to-forum-btn">Move to forum</button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="move-modal-actions"><button type="button" class="move-modal-cancel">Cancel</button></div>';
        echo '</div></div>';
    }
}


function displayPostDetails($id) {
    global $conn;
    $id = (int) $id;

    $sql = "SELECT * FROM forumthreads WHERE id = $id";
    $result = $conn->query($sql);

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $subj = htmlspecialchars($row['subject'], ENT_QUOTES, 'UTF-8');
        $author = htmlspecialchars($row['author'], ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars($row['date'], ENT_QUOTES, 'UTF-8');

        echo '<main class="forum-main">';
        echo '<a href="index.php" class="back-link">&larr; Back to threads</a>';
        echo "<h1 class=\"post-title\">{$subj}</h1>";
        echo "<div class=\"post-meta\">by {$author} on {$date}</div>";

        // Fetch and display the post body from forumbodies table
        $body_sql = "SELECT body FROM forumbodies WHERE id = $id";
        $body_result = $conn->query($body_sql);

        if ($body_result->num_rows > 0) {
            $body_row = $body_result->fetch_assoc();
            $raw = $body_row['body'];
            if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'ISO-8859-1');
            $body = post_body_with_embeds($raw);
            echo "<div class=\"post-body\">{$body}</div>";
        }

        // Fetch and display replies
        $child_sql = "SELECT * FROM forumthreads WHERE parent = $id ORDER BY date ASC";
        $child_result = $conn->query($child_sql);

        if ($child_result->num_rows > 0) {
            echo '<h2 class="replies-heading">Replies</h2>';
            while($child_row = $child_result->fetch_assoc()) {
                $csubj = htmlspecialchars($child_row['subject'], ENT_QUOTES, 'UTF-8');
                $cauthor = htmlspecialchars($child_row['author'], ENT_QUOTES, 'UTF-8');
                $cdate = htmlspecialchars($child_row['date'], ENT_QUOTES, 'UTF-8');
                echo "<div class=\"reply-item\">";
                echo "<a href='index.php?id={$child_row['id']}' class=\"reply-subject\">{$csubj}</a>";
                echo "<div class=\"reply-meta\">by {$cauthor} on {$cdate}</div>";
                echo "</div>";
            }
        }
        echo '</main>';
    } else {
        echo '<main class="forum-main"><p class="forum-empty">No such post.</p></main>';
    }
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$limit = 30;
$forum = isset($_GET['forum']) ? ($_GET['forum'] === 'all' ? 'all' : max(1, (int) $_GET['forum'])) : 'all';
$sort = (isset($_GET['sort']) && $_GET['sort'] === 'replies') ? 'replies' : 'topics';

if (isset($_GET['ajax'])) {
    displayThreads($page, $limit, true, $forum, $sort);
    $conn->close();
    exit;
}

displayThreads($page, $limit, false, $forum, $sort);

$conn->close();
?>

<script>
var hasForumAdmin = <?php echo ($hasForumAdmin ? 'true' : 'false'); ?>;
(function() {
  function esc(s) {
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
  }
  function formatAuthorMeta(r) {
    var a = r.author || '';
    var d = r.date || '';
    var sid = r.site_user_id;
    var avatar = r.avatar_url;
    var base = window.forumProfileBase || '../';
    var isRegistered = (sid !== undefined && sid !== null) || avatar;
    if (isRegistered) {
      var profileUrl = base + 'profile.php?username=' + encodeURIComponent(a);
      var src = avatar ? (avatar.indexOf('http') === 0 ? avatar : base + avatar) : '';
      var img = src ? '<img src="' + esc(src) + '" alt="" class="forum-author-avatar">' : '';
      return ' by ' + img + '<a href="' + esc(profileUrl) + '" class="forum-author-link">' + esc(a) + '</a> <span class="forum-registered-badge" title="Registered user">®</span> on ' + esc(d);
    }
    return ' by ' + esc(a) + ' on ' + esc(d);
  }
  /** Decode only numeric HTML character references (&#123; &#x1a2b;) so they display correctly. Does not interpret tags. */
  function decodeNumericEntities(s) {
    return String(s)
      .replace(/&#(\d+);/g, function(_, d) { return String.fromCharCode(parseInt(d, 10)); })
      .replace(/&#x([0-9a-fA-F]+);/g, function(_, h) { return String.fromCharCode(parseInt(h, 16)); });
  }
  function bodyToHtml(body) {
    if (!body) return '';
    var s = esc(decodeNumericEntities(body));
    s = s.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    return s.replace(/\n/g, '<br>');
  }

  function renderRepliesList(replies, container, nestClass) {
    if (!replies.length) return;
    var heading = document.createElement('div');
    heading.className = 'replies-heading';
    heading.textContent = 'Replies';
    container.appendChild(heading);
    var list = document.createElement('div');
    list.className = 'replies-list' + (nestClass ? ' ' + nestClass : '');
    replies.forEach(function(r) {
      var row = document.createElement('div');
      row.className = 'reply-row';
      row.setAttribute('data-id', r.id);
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'reply-expand-btn';
      btn.setAttribute('aria-expanded', 'false');
      btn.title = 'Expand';
      btn.textContent = '▶';
      var subj = document.createElement('span');
      subj.className = 'reply-subject';
      subj.textContent = r.subject;
      var meta = document.createElement('span');
      meta.className = 'reply-meta';
      meta.innerHTML = formatAuthorMeta(r);
      var replyHits = (r.hits != null ? (parseInt(r.hits, 10) || 0) : 0);
      var hitsSpan = document.createElement('span');
      hitsSpan.className = 'reply-hits';
      hitsSpan.textContent = ' hits: ' + replyHits;
      meta.appendChild(hitsSpan);
      var inline = document.createElement('div');
      inline.className = 'reply-inline';
      inline.hidden = true;
      row.appendChild(btn);
      row.appendChild(subj);
      row.appendChild(meta);
      row.appendChild(inline);
      list.appendChild(row);
    });
    container.appendChild(list);
  }

  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
    }

  function addReplyForm(wrap, parentId, parentSubject) {
    var replyBtn = document.createElement('button');
    replyBtn.type = 'button';
    replyBtn.className = 'reply-post-btn';
    replyBtn.textContent = 'Reply';
    replyBtn.setAttribute('data-parent-id', parentId);
    wrap.appendChild(replyBtn);
    var defaultSubject = parentSubject ? ('Re: ' + (parentSubject.length > 40 ? parentSubject.slice(0, 37) + '...' : parentSubject)) : '';
    if (defaultSubject.length > 50) defaultSubject = defaultSubject.slice(0, 47) + '...';
    var formWrap = document.createElement('div');
    formWrap.className = 'reply-form-wrap';
    formWrap.hidden = true;
    formWrap.innerHTML = '<form class="reply-form"><label class="reply-author-label">Name <input type="text" name="author" class="reply-author" maxlength="50" required></label><label>Subject <input type="text" name="subject" class="reply-subject-input" maxlength="50" placeholder="Leave blank for Re: parent title"></label><label>Message <textarea name="body" class="reply-body" rows="4"></textarea></label><button type="submit" class="reply-submit">Post</button> <button type="button" class="reply-cancel">Cancel</button></form>';
    wrap.appendChild(formWrap);
    var authorLabel = formWrap.querySelector('.reply-author-label');
    var authorInput = formWrap.querySelector('.reply-author');
    var subjectInput = formWrap.querySelector('.reply-subject-input');
    if (window.forumLoggedIn && window.forumUsername) {
      var base = window.forumProfileBase || '../';
      var profileUrl = base + 'profile.php?username=' + encodeURIComponent(window.forumUsername);
      var avatarHtml = (window.forumAvatarUrl) ? '<img src="' + esc(window.forumAvatarUrl) + '" alt="" class="forum-author-avatar">' : '';
      authorLabel.innerHTML = 'Name <span class="forum-author-display">' + avatarHtml + '<a href="' + profileUrl + '" class="forum-author-link">' + esc(window.forumUsername) + '</a> <span class="forum-registered-badge" title="Registered user">®</span></span><input type="hidden" name="author" class="reply-author" value="' + esc(window.forumUsername) + '">';
      authorInput = formWrap.querySelector('.reply-author');
    } else {
      authorInput.value = (window.forumGetAuthorForForm && window.forumGetAuthorForForm()) || getCookie('forum_author') || '';
    }
    subjectInput.placeholder = defaultSubject ? 'Default: ' + defaultSubject : 'Leave blank for Re: parent title';
    replyBtn.addEventListener('click', function() {
      (window.forumMaybeShowFirstPostWelcome || function() { return Promise.resolve(); })()
        .then(function() {
          formWrap.hidden = !formWrap.hidden;
          if (!formWrap.hidden) {
            if (!window.forumLoggedIn || !window.forumUsername) {
              authorInput = formWrap.querySelector('.reply-author');
              if (authorInput && authorInput.type !== 'hidden') {
                authorInput.value = (window.forumGetAuthorForForm && window.forumGetAuthorForForm()) || getCookie('forum_author') || '';
              }
            }
            if (!subjectInput.value) subjectInput.value = defaultSubject;
            var authorTextInput = formWrap.querySelector('.reply-author[type="text"]');
            (authorTextInput || subjectInput).focus();
          }
        })
        .catch(function() {});
    });
    formWrap.querySelector('.reply-cancel').addEventListener('click', function() { formWrap.hidden = true; });
    formWrap.querySelector('.reply-form').addEventListener('submit', function(e) {
      e.preventDefault();
      var fd = new FormData(this);
      fd.append('parent_id', parentId);
      if (formWrap.querySelector('.reply-subject-input').value.trim()) fd.append('subject', formWrap.querySelector('.reply-subject-input').value.trim());
      var submitBtn = formWrap.querySelector('.reply-submit');
      submitBtn.disabled = true;
      fetch('api/post.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.error) { alert(res.error); submitBtn.disabled = false; return; }
          formWrap.hidden = true;
          formWrap.querySelector('.reply-body').value = '';
          formWrap.querySelector('.reply-subject-input').value = defaultSubject;
          var list = wrap.querySelector('.replies-list');
          if (!list) {
            var heading = document.createElement('div');
            heading.className = 'replies-heading';
            heading.textContent = 'Replies';
            wrap.appendChild(heading);
            list = document.createElement('div');
            list.className = 'replies-list reply-nest';
            wrap.appendChild(list);
          }
          var row = document.createElement('div');
          row.className = 'reply-row';
          row.setAttribute('data-id', res.id);
          var btn = document.createElement('button');
          btn.type = 'button';
          btn.className = 'reply-expand-btn';
          btn.setAttribute('aria-expanded', 'false');
          btn.title = 'Expand';
          btn.textContent = '▶';
          var subj = document.createElement('span');
          subj.className = 'reply-subject';
          subj.textContent = res.subject;
          var meta = document.createElement('span');
          meta.className = 'reply-meta';
          meta.innerHTML = formatAuthorMeta(res);
          var inline = document.createElement('div');
          inline.className = 'reply-inline';
          inline.hidden = true;
          row.appendChild(btn);
          row.appendChild(subj);
          row.appendChild(meta);
          row.appendChild(inline);
          list.appendChild(row);
          bindReplyButtons(wrap);
        })
        .catch(function() { alert('Post failed.'); })
        .then(function() { submitBtn.disabled = false; });
    });
  }

  function addCollapseBtn(container, inlineEl) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'thread-collapse-btn';
    btn.setAttribute('aria-expanded', 'true');
    btn.title = 'Collapse';
    btn.textContent = 'Collapse';
    btn.addEventListener('click', function() {
      var replyRow = inlineEl.closest('.reply-row');
      if (replyRow) {
        var rowBtn = replyRow.querySelector('.reply-expand-btn');
        var rowInline = replyRow.querySelector('.reply-inline');
        if (rowBtn && rowInline) {
          rowInline.hidden = true;
          rowBtn.setAttribute('aria-expanded', 'false');
        }
      } else {
        var item = inlineEl.closest('.thread-item');
        if (item) toggleThread(item);
      }
    });
    container.appendChild(btn);
  }

  function updateExpandCollapseLabel(item, expanded) {
    var btn = item && item.querySelector('.thread-expand-btn');
    if (!btn) return;
    btn.textContent = expanded ? '\u25BC' : '\u25B6';
    btn.title = expanded ? 'Collapse' : 'Expand';
    btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
  }

  function fillInline(inlineEl, data, isReply) {
    inlineEl.innerHTML = '';
    var wrap = document.createElement('div');
    wrap.className = isReply ? 'reply-inline-body' : 'thread-inline-body';
    wrap.setAttribute('data-post-id', data.id);
    var rawBodyEl = document.createElement('textarea');
    rawBodyEl.className = 'post-body-raw';
    rawBodyEl.setAttribute('aria-hidden', 'true');
    rawBodyEl.style.display = 'none';
    rawBodyEl.value = data.body || '';
    wrap.appendChild(rawBodyEl);
    var header = document.createElement('div');
    header.className = 'post-header post-header--no-title';
    var title = document.createElement('span');
    title.className = 'post-title';
    title.textContent = data.subject;
    header.appendChild(title);
    var meta = document.createElement('span');
    meta.className = 'post-meta';
    meta.innerHTML = formatAuthorMeta(data);
    header.appendChild(meta);
    if (isReply) {
      var hits = data.hits != null ? (parseInt(data.hits, 10) || 0) : 0;
      var hitsSpan = document.createElement('span');
      hitsSpan.className = 'post-hits';
      hitsSpan.textContent = 'hits: ' + hits;
      header.appendChild(hitsSpan);
    }
    var copyLink = document.createElement('button');
    copyLink.type = 'button';
    copyLink.className = 'post-copy-link';
    copyLink.title = 'Copy link to this post';
    copyLink.textContent = 'Copy link';
    copyLink.setAttribute('data-post-id', data.id);
    header.appendChild(copyLink);
    if (hasForumAdmin) {
      var editBtn = document.createElement('button');
      editBtn.type = 'button';
      editBtn.className = 'post-edit';
      editBtn.title = 'Edit post';
      editBtn.textContent = 'Edit';
      editBtn.setAttribute('data-post-id', data.id);
      editBtn.addEventListener('click', function(ev) {
        ev.preventDefault();
        ev.stopPropagation();
        var f = wrap.querySelector('.post-edit-form');
        if (!f) return;
        var raw = wrap.querySelector('.post-body-raw');
        var tit = wrap.querySelector('.post-title');
        f.querySelector('.post-edit-subject').value = tit ? tit.textContent : '';
        f.querySelector('.post-edit-body').value = raw ? raw.value : '';
        f.hidden = false;
        var firstInput = f.querySelector('.post-edit-subject');
        if (firstInput) firstInput.focus();
      });
      header.appendChild(editBtn);
      var deleteBtn = document.createElement('button');
      deleteBtn.type = 'button';
      deleteBtn.className = 'post-delete';
      deleteBtn.title = 'Delete post';
      deleteBtn.textContent = 'Delete';
      deleteBtn.setAttribute('data-post-id', data.id);
      header.appendChild(deleteBtn);
      var moveBtn = document.createElement('button');
      moveBtn.type = 'button';
      moveBtn.className = 'post-move';
      moveBtn.title = 'Move post';
      moveBtn.textContent = 'Move';
      moveBtn.setAttribute('data-post-id', data.id);
      header.appendChild(moveBtn);
    }
    wrap.appendChild(header);
    var body = document.createElement('div');
    body.className = 'post-body' + (data.body || data.body_html ? '' : ' post-body--empty');
    body.innerHTML = (data.body_html != null ? data.body_html : (data.body ? bodyToHtml(data.body) : '')) || '<span class="post-body-placeholder">(no text)</span>';
    wrap.appendChild(body);
    var editForm = document.createElement('div');
    editForm.className = 'post-edit-form';
    editForm.hidden = true;
    editForm.innerHTML = '<label class="post-edit-label">Subject</label><input type="text" class="post-edit-subject" maxlength="50">' +
      '<label class="post-edit-label">Message</label><textarea class="post-edit-body" rows="6"></textarea>' +
      '<div class="post-edit-actions"><button type="button" class="post-edit-save">Save</button><button type="button" class="post-edit-cancel">Cancel</button></div>';
    wrap.appendChild(editForm);
    var collapseRow = document.createElement('div');
    collapseRow.className = 'thread-collapse-row';
    addCollapseBtn(collapseRow, inlineEl);
    wrap.appendChild(collapseRow);
    addReplyForm(wrap, data.id, data.subject);
    renderRepliesList(data.replies || [], wrap, 'reply-nest');
    inlineEl.appendChild(wrap);
  }

  function loadThread(id, inlineEl, isReply, btn) {
    if (inlineEl.hasAttribute('data-loaded')) {
      inlineEl.hidden = !inlineEl.hidden;
      var item = btn && btn.closest('.thread-item');
      if (btn && item) updateExpandCollapseLabel(item, !inlineEl.hidden);
      return Promise.resolve();
    }
    inlineEl.innerHTML = '<div class="thread-inline-loading">Loading…</div>';
    inlineEl.hidden = false;
    if (btn) btn.setAttribute('aria-expanded', 'true');
    return fetch('api/thread.php?id=' + encodeURIComponent(id))
      .then(function(res) { return res.json(); })
      .then(function(data) {
        if (data.error) {
          inlineEl.innerHTML = '<div class="thread-inline-loading">' + esc(data.error) + '</div>';
          return;
        }
        inlineEl.innerHTML = '';
        fillInline(inlineEl, data, isReply);
        inlineEl.setAttribute('data-loaded', '1');
        bindReplyButtons(inlineEl);
        if (!isReply && btn) updateExpandCollapseLabel(btn.closest('.thread-item'), true);
      })
      .catch(function() {
        inlineEl.innerHTML = '<div class="thread-inline-loading">Load failed.</div>';
      });
  }

  function toggleThread(item) {
    var btn = item.querySelector('.thread-expand-btn');
    var inline = item.querySelector('.thread-inline');
    var id = item.getAttribute('data-id');
    if (!inline || !id) return;
    var open = btn.getAttribute('aria-expanded') === 'true';
    if (open) {
      inline.hidden = true;
      updateExpandCollapseLabel(item, false);
    } else {
      loadThread(id, inline, false, btn);
    }
  }

  function initThreadList(container) {
    container = container || document;
    container.querySelectorAll('.thread-item').forEach(function(item) {
      if (item._headBound) return;
      item._headBound = true;
      var head = item.querySelector('.thread-item-head');
      var btn = item.querySelector('.thread-expand-btn');
      if (!head) return;
      updateExpandCollapseLabel(item, false);
      head.addEventListener('click', function(e) {
        if (e.target === btn || btn.contains(e.target)) return;
        e.preventDefault();
        toggleThread(item);
      });
      head.style.cursor = 'pointer';
    });
    container.querySelectorAll('.thread-expand-btn').forEach(function(btn) {
      if (btn._expandBound) return;
      btn._expandBound = true;
      var item = btn.closest('.thread-item');
      var inline = item && item.querySelector('.thread-inline');
      var id = item && item.getAttribute('data-id');
      if (!inline || !id) return;
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        toggleThread(item);
      });
    });
  }

  function toggleReplyRow(row) {
    var btn = row.querySelector('.reply-expand-btn');
    var inline = row.querySelector('.reply-inline');
    var id = row.getAttribute('data-id');
    if (!inline || !id) return;
    var open = btn.getAttribute('aria-expanded') === 'true';
    if (open) {
      inline.hidden = true;
      btn.setAttribute('aria-expanded', 'false');
    } else {
      loadThread(id, inline, true, btn);
    }
  }

  function bindReplyRowClick(container) {
    if (!container) return;
    container.querySelectorAll('.reply-row').forEach(function(row) {
      if (row._clickBound) return;
      row._clickBound = true;
      row.style.cursor = 'pointer';
      row.addEventListener('click', function(e) {
        if (e.target.classList.contains('reply-expand-btn')) return;
        var thisInline = row.querySelector('.reply-inline');
        if (thisInline && thisInline.contains(e.target)) return;
        e.preventDefault();
        toggleReplyRow(row);
      });
    });
  }

  function bindReplyButtons(container) {
    if (!container) container = document;
    container.querySelectorAll('.reply-expand-btn').forEach(function(btn) {
      if (btn._bound) return;
      btn._bound = true;
      var row = btn.closest('.reply-row');
      var inline = row && row.querySelector('.reply-inline');
      var id = row && row.getAttribute('data-id');
      if (!inline || !id) return;
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        var open = btn.getAttribute('aria-expanded') === 'true';
        if (open) {
          inline.hidden = true;
          btn.setAttribute('aria-expanded', 'false');
        } else {
          loadThread(id, inline, true, btn);
        }
      });
    });
    bindReplyRowClick(container);
  }

  function loadPage(page) {
    var inner = document.querySelector('.forum-main-inner');
    if (!inner) return;
    var main = document.querySelector('.forum-main');
    var forum = (main && main.getAttribute('data-forum')) || 'all';
    var sort = (main && main.getAttribute('data-sort')) || 'topics';
    var q = 'page=' + encodeURIComponent(page) + '&ajax=1';
    if (forum !== '1') q += '&forum=' + encodeURIComponent(forum);
    if (sort === 'replies') q += '&sort=replies';
    inner.innerHTML = '<div class="thread-inline-loading" style="padding:2rem;text-align:center">Loading…</div>';
    fetch('index.php?' + q)
      .then(function(r) { return r.text(); })
      .then(function(html) {
        inner.innerHTML = html;
        initThreadList(inner);
        var url = 'index.php?page=' + page;
        if (forum !== '1') url += '&forum=' + forum;
        if (sort === 'replies') url += '&sort=replies';
        history.pushState({ page: page }, '', url);
      })
      .catch(function() {
        inner.innerHTML = '<p class="forum-empty">Load failed.</p>';
      });
  }

  var forumMain = document.querySelector('.forum-main');
  if (forumMain) {
    forumMain.addEventListener('click', function(e) {
      var a = e.target.closest('a.pagination-btn');
      if (!a || !a.getAttribute('data-page') || !forumMain.contains(a)) return;
      e.preventDefault();
      loadPage(a.getAttribute('data-page'));
    });
    forumMain.addEventListener('submit', function(e) {
      var form = e.target.closest('form.pagination-go');
      if (!form || !forumMain.contains(form)) return;
      e.preventDefault();
      var input = form.querySelector('.pagination-page-input');
      var page = input && parseInt(input.value, 10);
      if (!page || page < 1) return;
      var nav = form.closest('.pagination');
      var total = nav && parseInt(nav.getAttribute('data-total'), 10);
      if (total && page > total) return;
      loadPage(page);
    });
  }

  initThreadList();

  var mainEl = document.querySelector('.forum-main');
  var expandId = mainEl && mainEl.getAttribute('data-expand-id');
  var postId = mainEl && mainEl.getAttribute('data-post-id');

  function expandPath(ids) {
    if (!ids || ids.length === 0) return Promise.resolve();
    var id = ids[0];
    var row = document.querySelector('.reply-row[data-id="' + id + '"]');
    if (!row) return Promise.resolve();
    var inline = row.querySelector('.reply-inline');
    var btn = row.querySelector('.reply-expand-btn');
    if (!inline || !btn) return Promise.resolve();
    return loadThread(id, inline, true, btn).then(function() {
      return expandPath(ids.slice(1));
    });
  }
  function scrollToAndHighlight(id) {
    var el = document.querySelector('.reply-inline-body[data-post-id="' + id + '"], .thread-inline-body[data-post-id="' + id + '"]');
    if (!el) {
      var row = document.querySelector('.reply-row[data-id="' + id + '"]');
      if (row) el = row.querySelector('.reply-inline .reply-inline-body');
    }
    if (el) {
      el.classList.add('post-highlight');
      el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      setTimeout(function() { el.classList.remove('post-highlight'); }, 2500);
    }
  }

  if (postId && postId !== '0') {
    fetch('api/post_info.php?id=' + encodeURIComponent(postId))
      .then(function(r) { return r.json(); })
      .then(function(info) {
        if (info.error) return;
        var initialPage = mainEl.getAttribute('data-initial-page') || '1';
        var currentForum = mainEl.getAttribute('data-forum') || '1';
        var forumParam = (info.forum != null && info.forum !== '') ? String(info.forum) : '1';
        var forumQ = '&forum=' + encodeURIComponent(forumParam);
        var sortParam = mainEl.getAttribute('data-sort');
        var sortQ = (sortParam === 'replies') ? '&sort=replies' : '';
        var replaceUrl = 'index.php?page=' + info.page + forumQ + sortQ + '&postid=' + postId + '&expand=' + info.threadId;
        if (String(info.page) !== String(initialPage) || String(forumParam) !== String(currentForum)) {
          location.replace(replaceUrl);
          return;
        }
        var item = document.querySelector('.thread-item[data-id="' + info.threadId + '"]');
        if (!item && expandId !== info.threadId) {
          location.replace(replaceUrl);
          return;
        }
        var inline = item && item.querySelector('.thread-inline');
        var btn = item && item.querySelector('.thread-expand-btn');
        if (item && inline && btn) {
          loadThread(info.threadId, inline, false, btn).then(function() {
            return expandPath(info.path.slice(1));
          }).then(function() {
            scrollToAndHighlight(postId);
          });
        }
      });
  } else if (expandId && expandId !== '0') {
    var item = document.querySelector('.thread-item[data-id="' + expandId + '"]');
    if (item) toggleThread(item);
  }

  if (mainEl && mainEl.getAttribute('data-initial-page')) {
    var initialPage = mainEl.getAttribute('data-initial-page');
    var forumParam = mainEl.getAttribute('data-forum');
    var sortParam = mainEl.getAttribute('data-sort');
    var url = 'index.php?page=' + initialPage;
    if (forumParam && forumParam !== '1') url += '&forum=' + forumParam;
    if (sortParam === 'replies') url += '&sort=replies';
    if (expandId && expandId !== '0') url += '&expand=' + expandId;
    if (postId && postId !== '0') url += '&postid=' + postId;
    history.replaceState({ page: initialPage }, '', url);
  }
  window.addEventListener('popstate', function(e) {
    if (e.state && e.state.page) loadPage(e.state.page);
  });

  document.body.addEventListener('click', function(e) {
    var btn = e.target.closest('.post-copy-link');
    if (btn) {
      e.preventDefault();
      var id = btn.getAttribute('data-post-id');
      if (!id) return;
      var base = location.origin + location.pathname;
      if (!/index\.php/.test(base)) base = base.replace(/\/?$/, '') + '/index.php';
      var url = base + (base.indexOf('?') >= 0 ? '&' : '?') + 'postid=' + id;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
          var t = btn.textContent;
          btn.textContent = 'Copied!';
          setTimeout(function() { btn.textContent = t; }, 1500);
        });
      } else {
        prompt('Copy this link:', url);
      }
      return;
    }
    var cancelBtn = e.target.closest('.post-edit-cancel');
    if (cancelBtn) {
      e.preventDefault();
      var form = cancelBtn.closest('.post-edit-form');
      if (form) form.hidden = true;
      return;
    }
    var saveBtn = e.target.closest('.post-edit-save');
    if (saveBtn) {
      e.preventDefault();
      var form = saveBtn.closest('.post-edit-form');
      var wrap = form && form.closest('.thread-inline-body, .reply-inline-body');
      if (!wrap) return;
      var id = wrap.getAttribute('data-post-id');
      var subject = form.querySelector('.post-edit-subject').value.trim();
      var body = form.querySelector('.post-edit-body').value;
      saveBtn.disabled = true;
      var fd = new FormData();
      fd.append('id', id);
      fd.append('subject', subject);
      fd.append('body', body);
      fetch('api/edit_post.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.error) { alert(data.error); return; }
          var titleEl = wrap.querySelector('.post-title');
          if (titleEl) titleEl.textContent = subject;
          var bodyEl = wrap.querySelector('.post-body');
          var rawEl = wrap.querySelector('.post-body-raw');
          if (rawEl) rawEl.value = body;
          if (bodyEl) {
            var displayHtml = (data.body_html != null ? data.body_html : (body ? bodyToHtml(body) : '')) || '';
            bodyEl.className = 'post-body' + (displayHtml ? '' : ' post-body--empty');
            bodyEl.innerHTML = displayHtml || '<span class="post-body-placeholder">(no text)</span>';
          }
          form.hidden = true;
        })
        .catch(function() { alert('Update failed.'); })
        .then(function() { saveBtn.disabled = false; });
      return;
    }
    var moveBtn = e.target.closest('.post-move');
    if (moveBtn) {
      e.preventDefault();
      var wrap = moveBtn.closest('.thread-inline-body, .reply-inline-body');
      if (!wrap) return;
      var modal = document.getElementById('move-modal');
      if (!modal) return;
      var id = wrap.getAttribute('data-post-id');
      var subject = (wrap.querySelector('.post-title') || {}).textContent || 'Post';
      modal.setAttribute('data-post-id', id);
      modal.querySelector('.move-modal-title').textContent = 'Move post: ' + subject.substring(0, 50) + (subject.length > 50 ? '…' : '');
      modal.querySelector('.move-modal-results').innerHTML = '';
      modal.querySelector('.move-modal-selected').hidden = true;
      modal.removeAttribute('data-selected-id');
      modal.removeAttribute('data-selected-subject');
      var forumSelect = modal.querySelector('.move-modal-forum-select');
      if (forumSelect && !forumSelect._populated) {
        forumSelect._populated = true;
        fetch('api/forums.php').then(function(r) { return r.json(); }).then(function(data) {
          if (data.forums && data.forums.length) {
            forumSelect.innerHTML = '<option value="">— Select forum —</option>';
            data.forums.forEach(function(f) {
              var opt = document.createElement('option');
              opt.value = f.id;
              opt.textContent = f.title;
              forumSelect.appendChild(opt);
            });
          }
        });
      }
      modal.hidden = false;
      return;
    }
    var deleteBtn = e.target.closest('.post-delete');
    if (deleteBtn) {
      e.preventDefault();
      if (!confirm('Delete this post? Replies will remain but will no longer appear under this thread.')) return;
      var wrap = deleteBtn.closest('.thread-inline-body, .reply-inline-body');
      if (!wrap) return;
      var id = wrap.getAttribute('data-post-id');
      var fd = new FormData();
      fd.append('id', id);
      deleteBtn.disabled = true;
      fetch('api/delete_post.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.error) { alert(data.error); deleteBtn.disabled = false; return; }
          var replyRow = wrap.closest('.reply-inline');
          if (replyRow) {
            replyRow = replyRow.parentElement;
            if (replyRow && replyRow.classList.contains('reply-row')) replyRow.remove();
          } else {
            var threadInline = wrap.closest('.thread-inline');
            if (threadInline) threadInline.innerHTML = '<div class="thread-inline-loading">Post deleted.</div>';
          }
        })
        .catch(function() { alert('Delete failed.'); })
        .then(function() { deleteBtn.disabled = false; });
      return;
    }
  });

  var moveModal = document.getElementById('move-modal');
  if (moveModal) {
    function doMove(targetParent) {
      var id = moveModal.getAttribute('data-post-id');
      if (!id) return;
      var fd = new FormData();
      fd.append('id', id);
      fd.append('target_parent', targetParent);
      fetch('api/move_post.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.error) { alert(data.error); return; }
          moveModal.hidden = true;
          location.reload();
        })
        .catch(function() { alert('Move failed.'); });
    }
    moveModal.addEventListener('click', function(e) {
      if (e.target.classList.contains('move-modal-backdrop') || e.target.classList.contains('move-modal-cancel')) {
        moveModal.hidden = true;
        return;
      }
      if (e.target.classList.contains('move-modal-make-topic')) {
        doMove(-1);
        return;
      }
      if (e.target.classList.contains('move-modal-search-btn')) {
        var q = moveModal.querySelector('.move-modal-search-input').value.trim();
        if (!q) { alert('Enter a search term.'); return; }
        var body = moveModal.querySelector('.move-modal-search-body').checked ? '1' : '0';
        var resultsEl = moveModal.querySelector('.move-modal-results');
        resultsEl.innerHTML = '<div class="move-modal-loading">Searching…</div>';
        fetch('api/search_posts.php?q=' + encodeURIComponent(q) + '&body=' + body)
          .then(function(r) { return r.json(); })
          .then(function(data) {
            var currentId = moveModal.getAttribute('data-post-id');
            if (data.error) { resultsEl.innerHTML = '<div class="move-modal-msg">' + esc(data.error) + '</div>'; return; }
            if (!data.posts || !data.posts.length) { resultsEl.innerHTML = '<div class="move-modal-msg">No posts found.</div>'; return; }
            var html = '';
            data.posts.forEach(function(p) {
              if (String(p.id) === String(currentId)) return;
              var subj = esc(p.subject.substring(0, 60)) + (p.subject.length > 60 ? '…' : '');
              var threadSubj = esc((p.thread_subject || '').substring(0, 50)) + ((p.thread_subject || '').length > 50 ? '…' : '');
              html += '<button type="button" class="move-result-item" data-id="' + p.id + '" data-subject="' + esc(p.subject) + '">' + subj + ' <span class="move-result-meta">— in ' + threadSubj + ' (#' + p.id + ')</span></button>';
            });
            resultsEl.innerHTML = html || '<div class="move-modal-msg">No other posts found.</div>';
          })
          .catch(function() { moveModal.querySelector('.move-modal-results').innerHTML = '<div class="move-modal-msg">Search failed.</div>'; });
        return;
      }
      if (e.target.classList.contains('move-result-item')) {
        var btn = e.target.closest('.move-result-item');
        if (!btn) return;
        moveModal.setAttribute('data-selected-id', btn.getAttribute('data-id'));
        moveModal.setAttribute('data-selected-subject', btn.getAttribute('data-subject') || '');
        var selEl = moveModal.querySelector('.move-modal-selected');
        selEl.querySelector('.move-modal-selected-text').textContent = 'Reply to: ' + (btn.getAttribute('data-subject') || '').substring(0, 50) + ' (#' + btn.getAttribute('data-id') + ')';
        selEl.hidden = false;
        return;
      }
      if (e.target.classList.contains('move-modal-clear')) {
        moveModal.removeAttribute('data-selected-id');
        moveModal.removeAttribute('data-selected-subject');
        moveModal.querySelector('.move-modal-selected').hidden = true;
        return;
      }
      if (e.target.classList.contains('move-modal-confirm')) {
        var sid = moveModal.getAttribute('data-selected-id');
        if (!sid) return;
        doMove(parseInt(sid, 10));
        return;
      }
      if (e.target.classList.contains('move-modal-move-to-forum-btn')) {
        var fid = moveModal.querySelector('.move-modal-forum-select').value;
        if (!fid) { alert('Select a forum first.'); return; }
        var postId = moveModal.getAttribute('data-post-id');
        if (!postId) return;
        var btn = e.target;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('id', postId);
        fd.append('target_forum_id', fid);
        fetch('api/move_post_to_forum.php', { method: 'POST', body: fd })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data.error) { alert(data.error); return; }
            moveModal.hidden = true;
            location.reload();
          })
          .catch(function() { alert('Move to forum failed.'); })
          .then(function() { btn.disabled = false; });
        return;
      }
    });
  }

  var newTopicWrap = document.getElementById('new-topic');
  var newTopicForm = document.getElementById('new-topic-form');
  var headerNewTopic = document.getElementById('header-new-topic');
  if (newTopicWrap && newTopicForm) {
    function getAuthorCookie() {
      var m = document.cookie.match(/(?:^|; )forum_author=([^;]*)/);
      return m ? decodeURIComponent(m[1]) : '';
    }
    function showNewTopic() {
      newTopicWrap.hidden = false;
      var authorInput = newTopicForm.querySelector('.new-topic-author');
      if (authorInput) {
        authorInput.value = (window.forumGetAuthorForForm && window.forumGetAuthorForForm()) || getAuthorCookie();
        authorInput.readOnly = !!(window.forumLoggedIn && window.forumUsername);
      }
    }
    function hideNewTopic() {
      newTopicWrap.hidden = true;
      location.hash = '';
    }
    if (location.hash === '#new-topic') {
      (window.forumMaybeShowFirstPostWelcome || function() { return Promise.resolve(); })()
        .then(function() { showNewTopic(); })
        .catch(function() { location.hash = ''; });
    }
    window.addEventListener('hashchange', function() {
      if (location.hash !== '#new-topic') {
        newTopicWrap.hidden = true;
        return;
      }
      (window.forumMaybeShowFirstPostWelcome || function() { return Promise.resolve(); })()
        .then(function() { showNewTopic(); })
        .catch(function() { location.hash = ''; });
    });
    if (headerNewTopic) {
      headerNewTopic.addEventListener('click', function(e) {
        e.preventDefault();
        (window.forumMaybeShowFirstPostWelcome || function() { return Promise.resolve(); })()
          .then(function() {
            location.hash = 'new-topic';
            showNewTopic();
          })
          .catch(function() {});
      });
    }
    newTopicWrap.querySelector('.new-topic-cancel').addEventListener('click', hideNewTopic);
    newTopicForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var fd = new FormData(newTopicForm);
      fd.append('parent_id', '0');
      fd.append('subject', newTopicForm.querySelector('.new-topic-subject').value);
      var btn = newTopicForm.querySelector('.new-topic-submit');
      btn.disabled = true;
      fetch('api/post.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.error) { alert(res.error); return; }
          location.href = 'index.php';
        })
        .catch(function() { alert('Post failed.'); })
        .then(function() { btn.disabled = false; });
    });
  }
})();
</script>

</body>
</html>

