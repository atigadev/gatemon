<?php
// Uji koneksi laptop ke server ini tanpa mengubah data (dipakai agent >= 1.13 sebelum pindah server):
// berhasil hanya bila server ini mengenal ID + token laptop (database sudah dipindah).
require __DIR__ . '/../db.php';
$d = auth_device();
json_out(['ok' => true, 'app' => 'gatemon', 'device' => $d['device_key'], 'server_time' => date('c')]);
