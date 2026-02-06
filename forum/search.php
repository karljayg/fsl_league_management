<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . "/config.php";
$current_forum = 'all';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Search - Forum</title>
<link rel="stylesheet" href="css/style.css?v=<?php echo filemtime(__DIR__ . '/css/style.css'); ?>">
<?php include __DIR__ . '/forum_theme_init.php'; ?>
</head>
<body>
<?php
include "header.php";
$url_searchtext = isset($_GET['searchtext']) ? htmlspecialchars($_GET['searchtext'], ENT_QUOTES, 'UTF-8') : '';
$url_author = isset($_GET['author']) ? htmlspecialchars($_GET['author'], ENT_QUOTES, 'UTF-8') : '';
$url_forum = isset($_GET['forum']) ? $_GET['forum'] : 'all';
$url_date_from = isset($_GET['DateRangeFrom']) ? htmlspecialchars($_GET['DateRangeFrom'], ENT_QUOTES, 'UTF-8') : '';
$url_date_to = isset($_GET['DateRangeTo']) ? htmlspecialchars($_GET['DateRangeTo'], ENT_QUOTES, 'UTF-8') : '';
$url_body = isset($_GET['body']) && $_GET['body'] === '1';
?>
<main class="forum-main search-page">
  <h1 class="search-heading">Search</h1>
  <form class="search-form" id="search-form" method="get" action="search.php">
    <label class="search-option">
      <span class="search-option-label">Search text (optional)</span>
      <input type="text" name="searchtext" class="search-query" placeholder="Search in subject…" value="<?php echo $url_searchtext; ?>" autofocus>
    </label>
    <label class="search-option">
      <span class="search-option-label">Forum</span>
      <select name="forum" class="search-forum-select">
        <option value="all"<?php echo ($url_forum === 'all') ? ' selected' : ''; ?>>All</option>
        <?php foreach ($forums as $f): ?>
        <option value="<?php echo $f['id']; ?>"<?php echo ((string)$f['id'] === (string)$url_forum) ? ' selected' : ''; ?>><?php echo $f['title']; ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="search-option">
      <span class="search-option-label">Author (optional)</span>
      <input type="text" name="author" class="search-author" placeholder="Filter by author" value="<?php echo $url_author; ?>">
    </label>
    <label class="search-option">
      <span class="search-option-label">Date range (optional)</span>
      <input type="date" name="DateRangeFrom" class="search-date-from" value="<?php echo $url_date_from; ?>"> – <input type="date" name="DateRangeTo" class="search-date-to" value="<?php echo $url_date_to; ?>">
    </label>
    <label class="search-body-option">
      <input type="checkbox" name="body" value="1" class="search-in-body"<?php echo $url_body ? ' checked' : ''; ?>>
      Also search in post body (slower, limited to first 3000 chars)
    </label>
    <button type="submit" class="search-submit">Search</button>
  </form>
  <div class="search-results" id="search-results" hidden></div>
</main>
<script>
(function() {
  var form = document.getElementById('search-form');
  var resultsEl = document.getElementById('search-results');

  function getParams() {
    var q = (form.querySelector('.search-query') && form.querySelector('.search-query').value) ? form.querySelector('.search-query').value.trim() : '';
    var body = form.querySelector('.search-in-body') && form.querySelector('.search-in-body').checked ? '1' : '0';
    var forum = (form.querySelector('.search-forum-select') && form.querySelector('.search-forum-select').value) ? form.querySelector('.search-forum-select').value : 'all';
    var author = (form.querySelector('.search-author') && form.querySelector('.search-author').value) ? form.querySelector('.search-author').value.trim() : '';
    var dateFrom = (form.querySelector('.search-date-from') && form.querySelector('.search-date-from').value) ? form.querySelector('.search-date-from').value : '';
    var dateTo = (form.querySelector('.search-date-to') && form.querySelector('.search-date-to').value) ? form.querySelector('.search-date-to').value : '';
    return { q: q, body: body, forum: forum, author: author, dateFrom: dateFrom, dateTo: dateTo };
  }

  function buildShareUrl(params) {
    var parts = [];
    if (params.q) parts.push('searchtext=' + encodeURIComponent(params.q));
    if (params.forum && params.forum !== 'all') parts.push('forum=' + encodeURIComponent(params.forum));
    if (params.author) parts.push('author=' + encodeURIComponent(params.author));
    if (params.dateFrom) parts.push('DateRangeFrom=' + encodeURIComponent(params.dateFrom));
    if (params.dateTo) parts.push('DateRangeTo=' + encodeURIComponent(params.dateTo));
    if (params.body === '1') parts.push('body=1');
    return 'search.php' + (parts.length ? '?' + parts.join('&') : '');
  }

  function runSearch(params) {
    var hasCriteria = params.q || params.author || params.dateFrom || params.dateTo;
    if (!hasCriteria) {
      resultsEl.hidden = false;
      resultsEl.innerHTML = '<p class="forum-empty">Enter at least one: search text, author, or date range.</p>';
      return;
    }
    resultsEl.hidden = false;
    resultsEl.innerHTML = '<p class="thread-inline-loading">Searching…</p>';
    var apiUrl = 'api/search.php?body=' + params.body;
    if (params.q) apiUrl += '&q=' + encodeURIComponent(params.q);
    if (params.forum && params.forum !== 'all') apiUrl += '&forum=' + encodeURIComponent(params.forum);
    if (params.author) apiUrl += '&author=' + encodeURIComponent(params.author);
    if (params.dateFrom) apiUrl += '&date_from=' + encodeURIComponent(params.dateFrom);
    if (params.dateTo) apiUrl += '&date_to=' + encodeURIComponent(params.dateTo);
    fetch(apiUrl)
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.error) {
          resultsEl.innerHTML = '<p class="forum-empty">' + (data.error || 'Search failed') + '</p>';
          return;
        }
        var threads = data.threads || [];
        if (threads.length === 0) {
          resultsEl.innerHTML = '<p class="forum-empty">No threads found.</p>';
          return;
        }
        var html = '<p class="search-results-label">Found ' + threads.length + ' thread(s)</p><ul class="thread-list">';
        var showForumLabels = params.forum === 'all' || !params.forum;
        threads.forEach(function(t) {
          var subj = escapeHtml(t.subject);
          var auth = escapeHtml(t.author);
          var date = escapeHtml(t.date);
          var page = t.page || 1;
          var postId = t.postId != null ? t.postId : t.id;
          var forum = t.forum && t.forum !== 1 ? '&forum=' + encodeURIComponent(t.forum) : '';
          var forumLabel = showForumLabels && t.forum_title ? ' <span class="thread-forum-label">' + escapeHtml(t.forum_title) + '</span>' : '';
          html += '<li class="thread-item"><div class="thread-item-head">';
          html += '<a href="index.php?page=' + page + '&postid=' + postId + forum + '" class="thread-link">' + subj + forumLabel + '</a>';
          html += '<span class="thread-meta">by ' + auth + ' on ' + date + '</span></div></li>';
        });
        html += '</ul>';
        resultsEl.innerHTML = html;
      })
      .catch(function() {
        resultsEl.innerHTML = '<p class="forum-empty">Search failed.</p>';
      });
  }

  form.addEventListener('submit', function(e) {
    e.preventDefault();
    var params = getParams();
    history.replaceState(null, '', buildShareUrl(params));
    runSearch(params);
  });

  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.has('searchtext') || urlParams.has('author') || urlParams.has('forum') || urlParams.has('DateRangeFrom') || urlParams.has('DateRangeTo')) {
    var params = getParams();
    runSearch(params);
  }

  function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
  }
})();
</script>
</main>
</body>
</html>
