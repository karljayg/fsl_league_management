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
<link rel="icon" href="../images/favicon.png" type="image/png">
<title>Orphaned Bodies - Forum</title>
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
  <h1 class="search-heading">Orphaned Bodies</h1>
  <p class="orphaned-intro">Body rows whose post was deleted (e.g. old code only deleted from forumthreads). Recover to re-create the thread row so the post shows again with subject &quot;(recovered #id)&quot; and author &quot;unknown&quot;—you can edit them after.</p>
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
  function render(bodies) {
    if (bodies.length === 0) {
      resultsEl.innerHTML = '<p class="forum-empty">No orphaned bodies.</p>';
      return;
    }
    var html = '<p class="search-results-label">' + bodies.length + ' orphaned body/bodies</p><ul class="orphaned-list">';
    bodies.forEach(function(b) {
      var preview = esc(b.body_preview);
      html += '<li class="orphaned-item" data-id="' + b.id + '">';
      html += '<span class="orphaned-subject">#' + b.id + '</span>';
      html += ' <span class="orphaned-meta">parent #' + b.parent + ' — ' + preview + '</span>';
      html += ' <span class="orphaned-actions">';
      html += '<button type="button" class="orphaned-recover" data-id="' + b.id + '">Recover</button>';
      html += '<button type="button" class="orphaned-delete" data-id="' + b.id + '">Delete</button>';
      html += '</span></li>';
    });
    html += '</ul>';
    resultsEl.innerHTML = html;
  }
  fetch('api/orphaned_bodies.php')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.error) {
        resultsEl.innerHTML = '<p class="forum-empty">' + esc(data.error) + '</p>';
        return;
      }
      render(data.bodies || []);
    })
    .catch(function() {
      resultsEl.innerHTML = '<p class="forum-empty">Load failed.</p>';
    });
  resultsEl.addEventListener('click', function(e) {
    var btn = e.target.closest('.orphaned-recover');
    if (btn) {
      e.preventDefault();
      var id = btn.getAttribute('data-id');
      btn.disabled = true;
      var fd = new FormData();
      fd.append('id', id);
      fetch('api/recover_body.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
          if (data.error) { alert(data.error); btn.disabled = false; return; }
          var li = btn.closest('.orphaned-item');
          if (li) li.remove();
          var ul = document.querySelector('.orphaned-list');
          if (ul && ul.children.length === 0) {
            resultsEl.innerHTML = '<p class="forum-empty">No orphaned bodies.</p>';
          } else {
            var label = document.querySelector('.search-results-label');
            if (label) label.textContent = (ul ? ul.children.length : 0) + ' orphaned body/bodies';
          }
        })
        .catch(function() { alert('Recover failed.'); btn.disabled = false; });
      return;
    }
    var delBtn = e.target.closest('.orphaned-delete');
    if (delBtn) {
      e.preventDefault();
      if (!confirm('Delete this body permanently? It cannot be recovered.')) return;
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
            resultsEl.innerHTML = '<p class="forum-empty">No orphaned bodies.</p>';
          } else {
            var label = document.querySelector('.search-results-label');
            if (label) label.textContent = (ul ? ul.children.length : 0) + ' orphaned body/bodies';
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
