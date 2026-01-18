<?php
// /api/helpers/utils.php
function sanitize($v) {
    return trim(htmlspecialchars($v, ENT_QUOTES));
}

function now_ts() {
    return date('Y-m-d H:i:s');
}
?>
