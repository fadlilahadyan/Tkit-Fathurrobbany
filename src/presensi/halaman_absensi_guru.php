<?php
// NYALAKAN RADAR ERROR
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/db.php';

// Cek login guru
if (!isset($_SESSION['id_user']) || $_SESSION['role'] !== 'guru') {
    header("Location: ../login.php");
    exit();
}

// Ambil data guru dengan pengamanan
$id_user = $_SESSION['id_user'];
$stmt_guru = $pdo->prepare("SELECT * FROM guru WHERE id_user = ?");
$stmt_guru->execute([$id_user]);
$guru = $stmt_guru->fetch(PDO::FETCH_ASSOC);

// PENGAMANAN: Pastikan data guru benar-benar ada
$id_guru = $guru ? $guru['id_guru'] : null;

$success = null;
$error = null;

// PROSES SIMPAN ABSENSI
if (isset($_POST['simpan'])) {
    if (!$id_guru) {
        $error = "GAGAL: Akun Anda tidak terdaftar secara valid sebagai Guru di database!";
    } else {
        $tanggal = $_POST['tanggal'];
        $id_kelas = $_POST['id_kelas'];
        
        try {
            $pdo->beginTransaction(); 
            
            foreach ($_POST['status'] as $id_siswa => $status) {
                $catatan = $_POST['catatan'][$id_siswa] ?? '';
                
                $stmt_cek = $pdo->prepare("SELECT id_absen FROM absensi WHERE id_siswa = ? AND tanggal = ?");
                $stmt_cek->execute([$id_siswa, $tanggal]);
                $cek = $stmt_cek->fetch();
                
                if ($cek) {
                    $stmt_upd = $pdo->prepare("UPDATE absensi SET status = ?, catatan = ?, input_by = ? WHERE id_absen = ?");
                    $stmt_upd->execute([$status, $catatan, $id_guru, $cek['id_absen']]);
                } else {
                    $id_absen = 'ABS' . time() . rand(10, 99);
                    $stmt_ins = $pdo->prepare("INSERT INTO absensi (id_absen, id_siswa, tanggal, status, catatan, input_by) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt_ins->execute([$id_absen, $id_siswa, $tanggal, $status, $catatan, $id_guru]);
                }
            }
            
            $pdo->commit();
            $success = "Data absensi berhasil disimpan! Silakan cek Dashboard.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "GAGAL MENYIMPAN! Detail Error: " . $e->getMessage();
        }
    }
}

// AMBIL DATA KELAS DENGAN PENGAMANAN
$kelas = [];
if ($id_guru) {
    $stmt_kelas = $pdo->prepare("SELECT * FROM kelas WHERE id_guru = ?");
    $stmt_kelas->execute([$id_guru]);
    $kelas = $stmt_kelas->fetchAll(PDO::FETCH_ASSOC);
}

$selected_kelas = $_GET['kelas'] ?? '';
$selected_tanggal = $_GET['tanggal'] ?? date('Y-m-d');
$siswa = [];
$absensi_exists = [];

if ($selected_kelas) {
    $stmt_siswa = $pdo->prepare("SELECT * FROM siswa WHERE id_kelas = ? AND status = 'Aktif' ORDER BY nama_siswa");
    $stmt_siswa->execute([$selected_kelas]);
    $siswa = $stmt_siswa->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($siswa as $s) {
        $stmt_abs = $pdo->prepare("SELECT * FROM absensi WHERE id_siswa = ? AND tanggal = ?");
        $stmt_abs->execute([$s['id_siswa'], $selected_tanggal]);
        $abs = $stmt_abs->fetch(PDO::FETCH_ASSOC);
        if ($abs) {
            $absensi_exists[$s['id_siswa']] = $abs;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Absensi Harian | Guru</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../dashboard.css">
    <style>
        .filter-grid { display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px; background: #f8fafc; padding: 20px; border-radius: 12px; align-items: end; margin-bottom: 30px; }
        .filter-item label { display: block; font-size: 0.85rem; font-weight: 600; color: #64748b; margin-bottom: 8px; }
        .form-control-custom { width: 100%; padding: 10px 15px; border: 1px solid #e2e8f0; border-radius: 8px; font-family: 'Inter', sans-serif; font-size: 0.95rem; }
        .btn-filter { background: #2563eb; color: white; padding: 10px 25px; border-radius: 8px; border: none; font-weight: 600; cursor: pointer; transition: 0.3s; }
        .btn-filter:hover { background: #1d4ed8; }
        .table-custom { width: 100%; border-collapse: separate; border-spacing: 0 10px; }
        .table-custom th { padding: 12px 15px; color: #64748b; font-weight: 600; font-size: 0.85rem; text-transform: uppercase; border-bottom: 1px solid #e2e8f0; }
        .table-custom tr td { padding: 15px; background: #ffffff; border-top: 1px solid #f1f5f9; border-bottom: 1px solid #f1f5f9; }
        .table-custom tr td:first-child { border-left: 1px solid #f1f5f9; border-radius: 10px 0 0 10px; }
        .table-custom tr td:last-child { border-right: 1px solid #f1f5f9; border-radius: 0 10px 10px 0; }
        .status-pill { padding: 8px 12px; border-radius: 6px; border: 1px solid #e2e8f0; background: #f8fafc; font-weight: 500; }
        .catatan-field { width: 100%; border: none; border-bottom: 1px dashed #cbd5e1; padding: 5px 0; color: #475569; }
        .catatan-field:focus { outline: none; border-bottom-color: #2563eb; }
        .btn-save-fixed { display: flex; align-items: center; gap: 10px; background: #22c55e; color: white; padding: 12px 30px; border-radius: 10px; border: none; font-weight: 700; margin-top: 20px; float: right; cursor: pointer; box-shadow: 0 4px 12px rgba(34, 197, 94, 0.2); }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-graduation-cap" style="color: var(--accent);"></i> TKIT FATHUROBANI
        </div>
        
        <div class="nav-group">
            <span class="nav-label">Utama</span>
            <a href="../dashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
        </div>

        <div class="nav-group">
            <span class="nav-label">Kelas</span>
            <a href="halaman_absensi_guru.php" class="nav-item active"><i class="fas fa-calendar-check"></i> Absensi Siswa</a>
            <a href="../laporan/laporan.php" class="nav-item"><i class="fas fa-chart-line"></i> Laporan Perkembangan</a>
        </div>

        <div class="nav-group">
            <span class="nav-label">Keuangan</span>
            <a href="../informasi_spp/informasi_spp.php" class="nav-item"><i class="fas fa-wallet"></i> Informasi SPP</a>
        </div>

        <div style="margin-top: auto;">
            <a href="../auth/logout.php" class="nav-item" style="color: #ef4444;"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <div class="main-content">
        <div class="header">
            <h1>📋 Presensi Siswa</h1>
            <p style="color: #64748b;">Kelola kehadiran harian siswa dengan mudah.</p>
        </div>

        <?php if (!$id_guru): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-triangle me-2"></i> <strong>Peringatan Sistem:</strong> Akun Anda belum terhubung dengan data Guru. Hubungi Administrator untuk mengecek tabel `guru`.
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert" style="background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold;">
                <i class="fas fa-check-circle me-2"></i> <?= $success ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-error">
                <i class="fas fa-exclamation-triangle me-2"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="GET">
                <div class="filter-grid">
                    <div class="filter-item">
                        <label>PILIH KELAS</label>
                        <select name="kelas" class="form-control-custom" required <?= !$id_guru ? 'disabled' : '' ?>>
                            <option value="">-- Pilih Kelas --</option>
                            <?php foreach ($kelas as $k): ?>
                                <option value="<?= htmlspecialchars($k['id_kelas']) ?>" <?= $selected_kelas == $k['id_kelas'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($k['nama_kelas']) ?> (<?= htmlspecialchars($k['tingkat']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-item">
                        <label>TANGGAL PRESENSI</label>
                        <input type="date" name="tanggal" class="form-control-custom" value="<?= htmlspecialchars($selected_tanggal) ?>" required <?= !$id_guru ? 'disabled' : '' ?>>
                    </div>
                    <button type="submit" class="btn-filter" <?= !$id_guru ? 'disabled' : '' ?>>
                        <i class="fas fa-filter me-2"></i> Tampilkan
                    </button>
                </div>
            </form>

            <?php if ($selected_kelas && !empty($siswa)): ?>
                <form method="POST">
                    <input type="hidden" name="tanggal" value="<?= htmlspecialchars($selected_tanggal) ?>">
                    <input type="hidden" name="id_kelas" value="<?= htmlspecialchars($selected_kelas) ?>">
                    
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th width="50">No</th>
                                <th>Nama Siswa</th>
                                <th width="150">Status Kehadiran</th>
                                <th>Keterangan / Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $no=1; foreach ($siswa as $s): 
                                $abs = $absensi_exists[$s['id_siswa']] ?? null;
                            ?>
                            <tr>
                                <td style="text-align: center; color: #94a3b8;"><?= $no++ ?></td>
                                <td>
                                    <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($s['nama_siswa']) ?></div>
                                    <div style="font-size: 0.75rem; color: #94a3b8;">NIS: <?= htmlspecialchars($s['nis']) ?></div>
                                </td>
                                <td>
                                    <select name="status[<?= $s['id_siswa'] ?>]" class="status-pill">
                                        <option value="Hadir" <?= ($abs['status']??'')=='Hadir' ? 'selected' : '' ?>>Hadir</option>
                                        <option value="Sakit" <?= ($abs['status']??'')=='Sakit' ? 'selected' : '' ?>>Sakit</option>
                                        <option value="Izin" <?= ($abs['status']??'')=='Izin' ? 'selected' : '' ?>>Izin</option>
                                        <option value="Alpha" <?= ($abs['status']??'')=='Alpha' ? 'selected' : '' ?>>Alpha</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="catatan[<?= $s['id_siswa'] ?>]" 
                                           class="catatan-field" value="<?= htmlspecialchars($abs['catatan']??'') ?>"
                                           placeholder="Tambahkan catatan jika ada...">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div style="overflow: hidden;">
                        <button type="submit" name="simpan" class="btn-save-fixed">
                            <i class="fas fa-save"></i> SIMPAN SEMUA DATA
                        </button>
                    </div>
                </form>
            <?php elseif ($selected_kelas): ?>
                <div style="text-align: center; padding: 60px 20px;">
                    <i class="fas fa-user-slash" style="font-size: 3rem; color: #e2e8f0; margin-bottom: 15px;"></i>
                    <p style="color: #64748b; font-weight: 500;">Belum ada siswa yang terdaftar di kelas ini.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>