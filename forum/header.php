<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include_once __DIR__ . '/../includes/nav.php';
require_once __DIR__ . '/forum_auth.php';
$hasForumAdmin = forum_has_chat_admin();

if (!isset($current_forum)) {
    $cf = isset($_GET['forum']) ? $_GET['forum'] : 'all';
    $current_forum = ($cf === 'all') ? 'all' : max(1, (int) $cf);
}

$forums = [];
if (isset($conn) && $conn && !$conn->connect_error) {
    $r = @$conn->query("SELECT id, title FROM forums ORDER BY id");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $forums[] = ['id' => (int) $row['id'], 'title' => htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8')];
        }
    }
}
if (empty($forums) && !isset($conn)) {
    require_once __DIR__ . '/config.php';
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    if ($conn && !$conn->connect_error) {
        $r = @$conn->query("SELECT id, title FROM forums ORDER BY id");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $forums[] = ['id' => (int) $row['id'], 'title' => htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8')];
            }
        }
    }
}
if (empty($forums)) {
    $forums = [['id' => 1, 'title' => 'General']];
}
?>
<nav class="forum-header">
  <a href="index.php" class="forum-title">Forum</a>
  <label class="forum-header-forum-label">
    <select id="forum-select" class="forum-select forum-header-select" title="Select forum" aria-label="Select forum">
      <option value="all"<?php echo ($current_forum === 'all') ? ' selected' : ''; ?>>All</option>
      <?php foreach ($forums as $f): ?>
        <option value="<?php echo $f['id']; ?>"<?php echo ($f['id'] === $current_forum) ? ' selected' : ''; ?>><?php echo $f['title']; ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <a href="search.php" class="forum-header-link">Search</a>
  <a href="#new-topic" class="forum-header-cta">Post New Topic</a>
  <?php if ($hasForumAdmin): ?>
  <button type="button" class="forum-header-link" id="forum-add-btn">Add forum</button>
  <a href="orphaned.php" class="forum-header-link">Orphaned Posts</a>
  <a href="orphaned_bodies.php" class="forum-header-link">Orphaned Bodies</a>
  <?php endif; ?>
  <?php if (isset($sort)): ?>
  <?php
    $other_sort = ($sort === 'replies') ? 'topics' : 'replies';
    $sort_url = 'index.php?sort=' . $other_sort . ((isset($current_forum) && $current_forum !== 'all' && $current_forum !== 1) ? '&forum=' . (int)$current_forum : '');
    $sort_label = ($sort === 'replies') ? 'Replies' : 'Topic';
  ?>
  Sort:<a href="<?php echo htmlspecialchars($sort_url, ENT_QUOTES, 'UTF-8'); ?>" class="forum-sort-toggle" title="Click to sort by <?php echo $other_sort === 'replies' ? 'latest reply' : 'topic'; ?> date"><?php echo htmlspecialchars($sort_label, ENT_QUOTES, 'UTF-8'); ?></a>
  <?php endif; ?>
  <label class="forum-theme-label">
    <span class="forum-theme-label-text">Theme</span>
    <select id="theme-select" class="forum-theme-select" title="Theme" aria-label="Theme">
      <option value="dark">Dark</option>
      <option value="mid" selected>Mid</option>
      <option value="light">Light</option>
    </select>
  </label>
</nav>
<?php if ($hasForumAdmin): ?>
<div id="add-forum-wrap" class="add-forum-wrap" hidden>
  <h2 class="add-forum-heading">Add forum</h2>
  <form id="add-forum-form" class="add-forum-form">
    <label>Title <input type="text" name="title" class="add-forum-title" maxlength="100" required placeholder="e.g. FSL"></label>
    <button type="submit" class="add-forum-submit">Add</button>
    <button type="button" class="add-forum-cancel">Cancel</button>
  </form>
</div>
<?php endif; ?>
<?php
$forum_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['username']);
$forum_username = $forum_logged_in ? $_SESSION['username'] : '';
$forum_profile_base = (isset($basePath) ? $basePath : '../');
$forum_avatar_url = '';
if ($forum_logged_in && isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/forum_user_lookup.php';
    $uid = (int) $_SESSION['user_id'];
    $author_by_id = ($forum_username !== '') ? [$uid => $forum_username] : [];
    $avatars = forum_get_user_avatars([$uid], $author_by_id);
    if (!empty($avatars[$uid]['avatar_url'])) {
        $au = $avatars[$uid]['avatar_url'];
        $forum_avatar_url = (strpos($au, 'http') === 0) ? $au : ($forum_profile_base . $au);
    }
}
?>
<div id="new-topic" class="new-topic-wrap" hidden>
  <h2 class="new-topic-heading">Post New Topic</h2>
  <form class="new-topic-form" id="new-topic-form">
    <input type="hidden" name="forum_id" value="<?php echo $current_forum; ?>">
    <label class="new-topic-author-label">Name
    <?php if ($forum_logged_in && $forum_username !== ''): ?>
    <span class="forum-author-display">
      <?php if ($forum_avatar_url !== ''): ?><img src="<?php echo htmlspecialchars($forum_avatar_url, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="forum-author-avatar"><?php endif; ?>
      <a href="<?php echo htmlspecialchars($forum_profile_base, ENT_QUOTES, 'UTF-8'); ?>profile.php?username=<?php echo rawurlencode($forum_username); ?>" class="forum-author-link"><?php echo htmlspecialchars($forum_username, ENT_QUOTES, 'UTF-8'); ?></a>
      <span class="forum-registered-badge" title="Registered user">®</span>
    </span>
    <input type="hidden" name="author" class="new-topic-author" value="<?php echo htmlspecialchars($forum_username, ENT_QUOTES, 'UTF-8'); ?>">
    <?php else: ?>
    <input type="text" name="author" class="new-topic-author" maxlength="50" required>
    <?php endif; ?>
    </label>
    <label>Subject <input type="text" name="subject" class="new-topic-subject" maxlength="50" required></label>
    <label>Message <textarea name="body" class="new-topic-body" rows="5"></textarea></label>
    <button type="submit" class="new-topic-submit">Post Topic</button>
    <button type="button" class="new-topic-cancel">Cancel</button>
  </form>
</div>

<div id="forum-welcome-modal" class="forum-welcome-modal" hidden>
  <div class="forum-welcome-backdrop"></div>
  <div class="forum-welcome-inner">
    <p class="forum-welcome-msg" data-guest>Thanks for posting! We encourage you to be polite, friendly, and positive. While we allow posting without an account, we have zero tolerance for bad behavior. Consider creating an account for accountability and to track your progress.</p>
    <p class="forum-welcome-msg" data-loggedin hidden>Welcome back! Thanks for posting—we’re glad you’re here. Keep it friendly and constructive.</p>
    <label class="forum-welcome-name-label" id="forum-welcome-name-label">Your name <input type="text" class="forum-welcome-author" maxlength="50" placeholder="Display name"></label>
    <div class="forum-welcome-actions">
      <button type="button" class="forum-welcome-continue">Continue</button>
      <button type="button" class="forum-welcome-cancel">Cancel</button>
    </div>
  </div>
</div>

<script>
window.forumLoggedIn = <?php echo (isset($_SESSION['user_id']) && isset($_SESSION['username']) ? 'true' : 'false'); ?>;
window.forumUsername = <?php echo json_encode(isset($_SESSION['username']) ? $_SESSION['username'] : ''); ?>;
window.forumProfileBase = <?php echo json_encode(isset($basePath) ? $basePath : '../'); ?>;
window.forumAvatarUrl = <?php echo json_encode($forum_avatar_url); ?>;
(function() {
  var themeSel = document.getElementById('theme-select');
  if (themeSel) {
    var t = document.documentElement.getAttribute('data-theme') || localStorage.getItem('forum-theme') || 'mid';
    themeSel.value = t;
    themeSel.addEventListener('change', function() {
      var v = themeSel.value;
      document.documentElement.setAttribute('data-theme', v);
      localStorage.setItem('forum-theme', v);
    });
  }
  document.body.addEventListener('change', function(e) {
    if (e.target.id === 'forum-select') {
      var v = e.target.value;
      var main = document.querySelector('.forum-main');
      var sort = main && main.getAttribute('data-sort');
      var params = [];
      if (v !== '1') params.push('forum=' + encodeURIComponent(v));
      if (sort === 'replies') params.push('sort=replies');
      window.location.href = 'index.php' + (params.length ? '?' + params.join('&') : '');
    }
  });
  var addBtn = document.getElementById('forum-add-btn');
  var addWrap = document.getElementById('add-forum-wrap');
  var addForm = document.getElementById('add-forum-form');
  if (addBtn && addWrap && addForm) {
    addBtn.addEventListener('click', function() {
      addWrap.hidden = false;
      addForm.querySelector('.add-forum-title').focus();
    });
    addWrap.querySelector('.add-forum-cancel').addEventListener('click', function() {
      addWrap.hidden = true;
    });
    addForm.addEventListener('submit', function(e) {
      e.preventDefault();
      var title = (addForm.querySelector('.add-forum-title') || {}).value;
      if (!title || !title.trim()) return;
      var fd = new FormData();
      fd.append('title', title.trim());
      addForm.querySelector('.add-forum-submit').disabled = true;
      fetch('api/add_forum.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.error) { alert(data.error); addForm.querySelector('.add-forum-submit').disabled = false; return; }
          var opt = document.createElement('option');
          opt.value = data.id;
          opt.textContent = data.title;
          opt.selected = true;
          var fs = document.getElementById('forum-select');
          if (fs) fs.appendChild(opt);
          addWrap.hidden = true;
          addForm.reset();
          window.location.href = 'index.php?forum=' + data.id;
        })
        .catch(function() { alert('Failed.'); addForm.querySelector('.add-forum-submit').disabled = false; });
    });
  }
})();
(function() {
  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.*+?^${}()|[\]\\])/g, '\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : '';
  }
  function getStoredAuthor() {
    try { return sessionStorage.getItem('forum_author') || ''; } catch (e) { return ''; }
  }
  window.forumGetAuthorForForm = function() {
    if (window.forumLoggedIn && window.forumUsername) return window.forumUsername;
    return getStoredAuthor() || getCookie('forum_author') || '';
  };
  window.forumMaybeShowFirstPostWelcome = function() {
    if (window.forumLoggedIn && window.forumUsername) return Promise.resolve();
    try {
      if (sessionStorage.getItem('forum_welcome_seen') === '1') return Promise.resolve();
    } catch (e) {}
    var modal = document.getElementById('forum-welcome-modal');
    var authorInput = modal && modal.querySelector('.forum-welcome-author');
    var continueBtn = modal && modal.querySelector('.forum-welcome-continue');
    var cancelBtn = modal && modal.querySelector('.forum-welcome-cancel');
    var msgGuest = modal && modal.querySelector('.forum-welcome-msg[data-guest]');
    var msgLoggedIn = modal && modal.querySelector('.forum-welcome-msg[data-loggedin]');
    var nameLabel = document.getElementById('forum-welcome-name-label');
    if (!modal || !continueBtn || !cancelBtn) return Promise.reject();
    var isLoggedIn = window.forumLoggedIn && window.forumUsername;
    if (isLoggedIn) {
      if (msgGuest) msgGuest.hidden = true;
      if (msgLoggedIn) msgLoggedIn.hidden = false;
      if (nameLabel) nameLabel.hidden = true;
    } else {
      if (msgGuest) msgGuest.hidden = false;
      if (msgLoggedIn) msgLoggedIn.hidden = true;
      if (nameLabel) nameLabel.hidden = false;
      if (authorInput) {
        authorInput.value = getStoredAuthor() || getCookie('forum_author') || '';
        authorInput.focus();
      }
    }
    modal.hidden = false;
    if (!isLoggedIn && authorInput) authorInput.focus();
    return new Promise(function(resolve, reject) {
      function done(ok) {
        modal.hidden = true;
        if (ok) {
          if (isLoggedIn) {
            try { sessionStorage.setItem('forum_welcome_seen', '1'); } catch (e) {}
            resolve();
            return;
          }
          var name = authorInput.value.trim();
          if (!name) {
            alert('Please enter your name.');
            if (authorInput) authorInput.focus();
            return;
          }
          try {
            sessionStorage.setItem('forum_author', name);
            sessionStorage.setItem('forum_welcome_seen', '1');
          } catch (e) {}
          resolve();
        } else reject();
      }
      continueBtn.onclick = function() { done(true); };
      cancelBtn.onclick = function() { done(false); };
      modal.querySelector('.forum-welcome-backdrop').onclick = function() { done(false); };
    });
  };
})();
</script>