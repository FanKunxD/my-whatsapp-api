<?php
// baileys_api.php (Versi Final dengan Perbaikan Binding)
header("Content-Type: application/json");
require "config.php"; 

// Cek koneksi database segera. Jika gagal, bot akan memberitahu user.
if ($conn->connect_error) {
    // Memberikan error status 500 jika koneksi DB gagal
    http_response_code(500); 
    echo json_encode(["status" => "error", "message" => "Internal Server Error: DB Connection Error: " . $conn->connect_error]);
    exit;
}

// --- KONFIGURASI BOT WAJIB GANTI ---
// GANTI DENGAN TOKEN RAHASIA YANG SAMA PERSIS DI index.js Baileys Anda
define('API_SECRET_TOKEN', 'TOKEN_WA_RAHASIA_12345'); 
// Batasan pendaftaran baru per nomor WA
$MAX_LIMIT = 5; 
// -----------------------------------

$body = file_get_contents("php://input");
$data = json_decode($body, true);

// 1. Verifikasi Keamanan Token
if (empty($data['token']) || $data['token'] !== API_SECRET_TOKEN) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
    exit;
}

// 2. Ambil Data Pesan
$from_wa = $data['sender'] ?? ''; // JID WhatsApp pengirim
$message_text = $data['text'] ?? ''; 

if (empty($from_wa) || empty($message_text)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing parameters."]);
    exit;
}

// 3. Parsing Perintah (Menggunakan strtoupper untuk perintah, dan pesan asli untuk argumen)
$message_text_upper = trim(strtoupper($message_text));
$parts = explode(' ', $message_text_upper, 2);
$command = $parts[0];
// Ambil argumen dari pesan asli agar casing username tetap terjaga.
$arg = trim(substr($message_text, strlen($command))) ?? ''; 

$reply_text = "Perintah tidak dikenali. Ketik *CEK <Username>* atau *DAFTAR <UsernameAnda>* untuk mendaftar.";

try {
    switch ($command) {
        
        // ======================================
        // LOGIKA PERINTAH DAFTAR (Pendaftaran User)
        // ======================================
        case 'DAFTAR':
            
            $user_key = $arg;
            
            if (empty($user_key)) {
                $reply_text = "Format salah. Gunakan: *DAFTAR <UsernameAnda>*\nContoh: DAFTAR BudiGanteng";
                break;
            }

            // Sanitasi username
            $user_key = $conn->real_escape_string($user_key);

            // --- Cek Limitasi berdasarkan Nomor WA ($from_wa) ---
            $cek_limit = $conn->prepare("SELECT COUNT(*) FROM user_keys WHERE ip_addr = ?");
            if (!$cek_limit) throw new Exception("Prepare Cek Limit Failed: " . $conn->error);
            
            $cek_limit->bind_param("s", $from_wa);
            $cek_limit->execute();
            $cek_limit->bind_result($count_existing);
            $cek_limit->fetch();
            $cek_limit->close();

            if ($count_existing >= $MAX_LIMIT) {
                $reply_text = "⛔ *Limit Pendaftaran Tercapai!* ⛔\nAnda sudah mendaftarkan $MAX_LIMIT Username. Silahkan hubungi Admin ($ADMIN_WA) jika ingin menghapus data lama.";
                break;
            }
            
            // --- Cek apakah Username sudah ada ---
            $cek_exist = $conn->prepare("SELECT id FROM user_keys WHERE user_key = ? LIMIT 1");
            if (!$cek_exist) throw new Exception("Prepare Cek Exist Failed: " . $conn->error);
            
            $cek_exist->bind_param("s", $user_key);
            $cek_exist->execute();
            $res_exist = $cek_exist->get_result();
            
            if ($res_exist->num_rows > 0) {
                $reply_text = "❌ Username *$user_key* sudah digunakan. Coba nama lain.";
                $cek_exist->close();
                break;
            }
            $cek_exist->close();
            
            // --- Pendaftaran Berhasil (INSERT) ---
            $requester_note = "Daftar via Bot WA";
            $tier = "Regular";
            $expires_val = 0; // DIGANTI DARI NULL MENJADI 0 (INTEGER) UNTUK MENGHINDARI ERROR BINDING
            $approved = 0; // Default: 0 (pending, perlu disetujui Admin)

            $stmt = $conn->prepare("
                INSERT INTO user_keys (user_key, requester_name, tier, request_note, expires, approved, ip_addr, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            if (!$stmt) throw new Exception("Prepare Insert Failed: " . $conn->error);
            
            // Urutan bind: ssssisi (string, string, string, string, integer, integer, string)
            $stmt->bind_param("ssssisi", 
                $user_key, 
                $user_key, 
                $tier, 
                $requester_note, 
                $expires_val, // Menggunakan 0
                $approved, 
                $from_wa 
            );

            if ($stmt->execute()) {
                $reply_text = "✅ *Pendaftaran Berhasil!* ✅\n\nUsername: *$user_key*\nStatus: Pending (Menunggu Persetujuan Admin)\n\nSilakan tunggu konfirmasi dari Admin: " . $ADMIN_WA;
                
                // Notifikasi ke Admin (ditangkap oleh Bot Baileys)
                $admin_notification = "Pendaftaran Baru:\nUser: *$user_key*\nWA: " . str_replace("@s.whatsapp.net", "", $from_wa);
                
                // Kirim balasan dengan notifikasi admin ke Baileys Bot
                echo json_encode(["status" => "ok", "reply_text" => $reply_text, "admin_notif" => $admin_notification]);
                $stmt->close();
                exit; 
                
            } else {
                // Jika eksekusi gagal (misalnya kolom tidak ditemukan/error data type)
                throw new Exception("Execute Insert Failed: " . $stmt->error);
            }
            
            $stmt->close();
            break;

        // ======================================
        // LOGIKA PERINTAH CEK
        // ======================================
        case 'CEK':
            if (empty($arg)) {
                $reply_text = "Format salah. Gunakan: CEK <UsernameAnda>";
                break;
            }
            $username = $conn->real_escape_string($arg);
            
            $stmt = $conn->prepare("SELECT tier, expires, approved FROM user_keys WHERE user_key = ? LIMIT 1");
            if (!$stmt) throw new Exception("Prepare Cek Failed: " . $conn->error);
            
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $res = $stmt->get_result();

            if ($res->num_rows > 0) {
                $row = $res->fetch_assoc();
                $status = (intval($row['approved']) == 1) ? "APPROVED ✅" : "PENDING 🕒";
                
                $expiry_text = "N/A (Seumur Hidup)";
                $expire_epoch = intval($row['expires']);

                if (!empty($row['expires']) && $expire_epoch > 0) {
                    $expiry_text = date('d M Y H:i:s', $expire_epoch);
                    if (time() > $expire_epoch) {
                        $status = "EXPIRED ❌";
                    }
                }
                
                $reply_text = "✨ STATUS USERNAME: " . $username . "\n";
                $reply_text .= "Tier: " . $row['tier'] . "\n";
                $reply_text .= "Status: " . $status . "\n";
                $reply_text .= "Kadaluarsa: " . $expiry_text;
            } else {
                $reply_text = "Username *" . $username . "* tidak ditemukan di database.";
            }
            $stmt->close();
            break;
            
        default:
            $reply_text = "Perintah tidak dikenali. Ketik *CEK <Username>* atau *DAFTAR <UsernameAnda>* untuk mendaftar.";
            break;
    }
} catch (Exception $e) {
    // Tangkap semua error PHP/DB dan laporkan kembali ke Bot
    
    // Memberikan pesan error yang jelas kepada pengguna (termasuk detail error dari Exception)
    $reply_text = "⛔ *TERJADI KESALAHAN SERVER!* ⛔\nMohon laporkan ke Admin ($ADMIN_WA) dengan detail error:\n\n" . $e->getMessage();
}

// 4. Kirim Balasan Akhir ke Baileys Bot
echo json_encode(["status" => "ok", "reply_text" => $reply_text]);
?>
