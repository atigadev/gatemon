<?php
// Root aplikasi: langsung arahkan ke dashboard (halaman login bila belum masuk).
header('Location: admin/index.php', true, 302);
exit;
