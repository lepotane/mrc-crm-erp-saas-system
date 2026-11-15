// MRC Masaüstü pilotu — native köprü (Faz 8).
//
// Felsefe GeneralEcu ile aynı: ince istemci. Bütün iş mantığı sunucuda (API v1);
// bu katman YALNIZ tarayıcının yapamadıklarını yapar:
//   - cihaz kimliği (hostname + OS + uygulama sürümü) → sunucu „hangi makine" bilir,
//   - yazıcı listesi (işletim sisteminden) → sessiz fiş/fatura baskısının kapısı.
//
// Ağ işi YOKTUR burada: API konuşması önyüzdeki fetch ile olur (Tauri webview'in
// kendi fetch'i; CSP tauri.conf.json'da tanımlı API adreslerine açıktır).

use std::process::Command;

/// Cihaz kimliği: sunucunun „hangi makineden bağlanıldı" sorusunun cevabı.
/// (API tarafındaki IP + User-Agent damgasını tamamlar; hostname'i yalnızca
/// makinenin kendisi söyleyebilir.)
#[tauri::command]
fn get_device_info(app: tauri::AppHandle) -> serde_json::Value {
    let hostname = std::env::var("COMPUTERNAME")
        .or_else(|_| std::env::var("HOSTNAME"))
        .unwrap_or_else(|_| "bilinmiyor".to_string());

    serde_json::json!({
        "hostname": hostname,
        "os": std::env::consts::OS,
        "arch": std::env::consts::ARCH,
        "app_version": app.package_info().version.to_string(),
    })
}

/// Yazıcı listesi: sessiz baskı için işletim sisteminin gördüğü yazıcılar.
/// Windows: PowerShell Get-Printer → JSON. Bulunamazsa/boşsa boş liste döner
/// (hata fırlatılmaz — yazıcısız kasa da geçerli kurulumdur).
#[tauri::command]
fn list_printers() -> Vec<serde_json::Value> {
    #[cfg(target_os = "windows")]
    {
        let out = Command::new("powershell")
            .args([
                "-NoProfile",
                "-NonInteractive",
                "-Command",
                "Get-Printer | Select-Object Name,DriverName,PortName | ConvertTo-Json -Compress",
            ])
            .output();

        if let Ok(out) = out {
            if out.status.success() {
                let text = String::from_utf8_lossy(&out.stdout);
                let trimmed = text.trim();
                if !trimmed.is_empty() {
                    // Tek yazıcıda obje, çok yazıcıda dizi döner — ikisini de karşıla.
                    if let Ok(v) = serde_json::from_str::<serde_json::Value>(trimmed) {
                        return match v {
                            serde_json::Value::Array(items) => items,
                            single => vec![single],
                        };
                    }
                }
            }
        }
        return vec![];
    }

    #[cfg(not(target_os = "windows"))]
    {
        // macOS/Linux: CUPS listesi (lpstat). Yoksa boş liste.
        let out = Command::new("lpstat").arg("-p").output();
        if let Ok(out) = out {
            if out.status.success() {
                return String::from_utf8_lossy(&out.stdout)
                    .lines()
                    .filter_map(|l| l.strip_prefix("printer "))
                    .map(|l| {
                        serde_json::json!({
                            "Name": l.split_whitespace().next().unwrap_or(l),
                            "DriverName": null,
                            "PortName": null,
                        })
                    })
                    .collect();
            }
        }
        vec![]
    }
}

#[cfg_attr(mobile, tauri::mobile_entry_point)]
pub fn run() {
    tauri::Builder::default()
        .plugin(tauri_plugin_opener::init())
        .invoke_handler(tauri::generate_handler![get_device_info, list_printers])
        .run(tauri::generate_context!())
        .expect("error while running tauri application");
}
