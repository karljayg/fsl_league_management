<?php
/** Participation tools: Forum selector, Search, Post New Topic. Requires $forums, $current_forum. */
if (!isset($forums)) $forums = [['id' => 1, 'title' => 'General']];
if (!isset($current_forum)) $current_forum = 'all';
?>
<div class="forum-action-bar">
  <label class="forum-action-forum-label">
    <span class="forum-action-forum-text">Forum</span>
    <select id="forum-select" class="forum-select forum-action-select" title="Select forum" aria-label="Select forum">
      <option value="all"<?php echo ($current_forum === 'all') ? ' selected' : ''; ?>>All</option>
      <?php foreach ($forums as $f): ?>
        <option value="<?php echo $f['id']; ?>"<?php echo ($f['id'] === $current_forum) ? ' selected' : ''; ?>><?php echo $f['title']; ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <a href="search.php" class="forum-action-link">Search</a>
  <a href="#new-topic" class="forum-action-cta">Post New Topic</a>
</div>
