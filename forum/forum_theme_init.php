<?php
$urlTheme = isset($_GET['theme']) ? strtolower(trim($_GET['theme'])) : '';
$urlTheme = in_array($urlTheme, ['dark', 'light', 'mid'], true) ? $urlTheme : null;
?>
<script>(function(){var u=<?php echo $urlTheme ? json_encode($urlTheme) : 'null'; ?>;var t=u||localStorage.getItem('forum-theme')||'mid';if(u)localStorage.setItem('forum-theme',t);document.documentElement.setAttribute('data-theme',t);})();</script>
