<?php
require __DIR__ . '/includes/bootstrap.php';
$t = in_str('t');
if ($t !== '' && hash_equals(csrf_token(), $t)) {
    logout_user();
}
redirect('index.php');
