<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/config.php";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Orphaned Posts - Forum</title>
<link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
<?php include __DIR__ . '/forum_theme_init.php'; ?>
</head>
<body>
<?php
include "header.php";
if (!isset($hasForumAdmin) || !$hasForumAdmin) {
    echo '<main class="forum-main search-page"><p class="forum-empty">Access denied. Chat admin permission required.</p></main></body></html>';
    exit;
}
?>
<main class="forum-main search-page">
  <h1 class="search-heading">Orphaned Posts</h1>
  <p class="orphaned-intro">Replies whose parent post was deleted. They no longer appear in any thread. Promote to a new topic or delete.</p>
  <div class="orphaned-results" id="orphaned-results">
    <p class="thread-inline-loading">Loading…</p>
  </div>
</main>
<script>
(function() {
  var resultsEl = document.getElementById('orphaned-results');
  function esc(s) {
    var div = document.createElement('div');
    div.textContent = s == null ? '' : s;
    return div.innerHTML;
  }
  function getPostUrl(id) {
    var base = location.origin + location.pathname.replace(/orphaned\.php$/, 'index.php');
    return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'postid=' + id;
  }
  function render(posts) {
    if (posts.length === 0) {
      resultsEl.innerHTML = '<p class="forum-empty">No orphaned posts.</p>';
      return;
    }
    var html = '<p class="search-results-label">' + posts.length + ' orphaned post(s)</p><ul class="orphaned-list">';
    posts.forEach(function(p) {
      var subj = esc(p.subject);
      var auth = esc(p.author);
      var date = esc(p.date);
      html += '<li class="orphaned-item" data-id="' + p.id + '">';
      html += '<span class="orphaned-subject">' + subj + '</span>';
      html += ' <span class="orphaned-meta">by ' + auth + ' on ' + date + ' (was reply to #' + p.parent + ')</span>';
      html += ' <span class="orphaned-actions">';
      html += '<button type="button" class="orphaned-copy-link" data-id="' + p.id + '" title="Copy link">Copy link</button>';
      html += '<button type="button" class="orphaned-make-topic" data-id="' + p.id + '">Make topic</button>';
      html += '<button type="button" class="orphaned-delete" data-id="' + p.id + '">Delete</button>';
      html += '</span></li>';
    });
    html += '</ul>';
    resultsEl.innerHTML = html;
  }
  fetch('api/orphaned.php')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.error) {
        resultsEl.innerHTML = '<p class="forum-empty">' + esc(data.error) + '</p>';
        return;
      }
      render(data.posts || []);
    })
    .catch(function() {
      resultsEl.innerHTML = '<p class="forum-empty">Load failed.</p>';
    });
  resultsEl.addEventListener('click', function(e) {
    var copyBtn = e.target.closest('.orphaned-copy-link');
    if (copyBtn) {
      e.preventDefault();
      var id = copyBtn.getAttribute('data-id');
      var url = getPostUrl(id);
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(url).then(function() {
          var t = copyBtn.textContent;
          copyBtn.textContent = 'Copied!';
          setTimeout(function() { copyBtn.textContent = t; }, 1500);
        });
      } else {
        prompt('Copy this link:', url);
      }
      return;
    }
    var makeBtn = e.target.closest('.orphaned-make-topic');
    if (makeBtn) {
      e.preventDefault();
      var id = makeBtn.getAttribute('data-id');
      makeBtn.disabled = true;
      var fd = new FormData();
      fd.append('id', id);
      fd.append('target_parent', '-1');
      fetch('api/move_post.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.error) { alert(data.error); makeBtn.disabled = false; return; }
          var li = makeBtn.closest('.orphaned-item');
          if (li) li.remove();
          var ul = document.querySelector('.orphaned-list');
          if (ul && ul.children.length === 0) {
            resultsEl.innerHTML = '<p class="forum-empty">No orphaned posts.</p>';
          } else {
            var label = document.querySelector('.search-results-label');
            if (label) label.textContent = (ul ? ul.children.length : 0) + ' orphaned post(s)';
          }
        })
        .catch(function() { alert('Failed.'); makeBtn.disabled = false; });
      return;
    }
    var delBtn = e.target.closest('.orphaned-delete');
    if (delBtn) {
      e.preventDefault();
      if (!confirm('Delete this post permanently?')) return;
      var id = delBtn.getAttribute('data-id');
      delBtn.disabled = true;
      var fd = new FormData();
      fd.append('id', id);
      fetch('api/delete_post.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.error) { alert(data.error); delBtn.disabled = false; return; }
          var li = delBtn.closest('.orphaned-item');
          if (li) li.remove();
          var ul = document.querySelector('.orphaned-list');
          if (ul && ul.children.length === 0) {
            resultsEl.innerHTML = '<p class="forum-empty">No orphaned posts.</p>';
          } else {
            var label = document.querySelector('.search-results-label');
            if (label) label.textContent = (ul ? ul.children.length : 0) + ' orphaned post(s)';
          }
        })
        .catch(function() { alert('Delete failed.'); delBtn.disabled = false; });
      return;
    }
  });
})();
</script>
</body>
</html>
