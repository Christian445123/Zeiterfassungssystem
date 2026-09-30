using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using Microsoft.Win32;

namespace Zeiterfassung;

/// <summary>Lokale Einstellungen. API-Key und Token werden per Windows-DPAPI (nur dieser Benutzer) verschlüsselt gespeichert.</summary>
public sealed class AppSettings
{
    public string ServerUrl { get; set; } = "";
    public string ApiKeyProtected { get; set; } = "";
    public string TokenProtected { get; set; } = "";
    public string LastUsername { get; set; } = "";
    public bool Activated { get; set; }

    [System.Text.Json.Serialization.JsonConverter(typeof(System.Text.Json.Serialization.JsonStringEnumConverter))]
    public UpdateMode UpdateMode { get; set; } = UpdateMode.Server;

    static readonly string PathFile = Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "Zeiterfassung", "settings.json");

    [System.Text.Json.Serialization.JsonIgnore]
    public string ApiKey
    {
        get => Unprotect(ApiKeyProtected);
        set => ApiKeyProtected = Protect(value);
    }

    [System.Text.Json.Serialization.JsonIgnore]
    public string Token
    {
        get => Unprotect(TokenProtected);
        set => TokenProtected = Protect(value);
    }

    public static AppSettings Load()
    {
        try
        {
            if (File.Exists(PathFile))
                return JsonSerializer.Deserialize<AppSettings>(File.ReadAllText(PathFile)) ?? new AppSettings();
        }
        catch { /* defekte Datei -> neu anfangen */ }
        return new AppSettings();
    }

    public void Save()
    {
        Directory.CreateDirectory(Path.GetDirectoryName(PathFile)!);
        File.WriteAllText(PathFile, JsonSerializer.Serialize(this, new JsonSerializerOptions { WriteIndented = true }));
    }

    static string Protect(string plain) => string.IsNullOrEmpty(plain) ? "" :
        Convert.ToBase64String(ProtectedData.Protect(Encoding.UTF8.GetBytes(plain), null, DataProtectionScope.CurrentUser));

    static string Unprotect(string cipher)
    {
        if (string.IsNullOrEmpty(cipher)) return "";
        try { return Encoding.UTF8.GetString(ProtectedData.Unprotect(Convert.FromBase64String(cipher), null, DataProtectionScope.CurrentUser)); }
        catch { return ""; }
    }

    /// <summary>Stabile Geräte-ID: SHA-256 der Windows-MachineGuid.</summary>
    public static string MachineId()
    {
        string guid = "";
        try
        {
            using var key = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64)
                .OpenSubKey(@"SOFTWARE\Microsoft\Cryptography");
            guid = key?.GetValue("MachineGuid") as string ?? "";
        }
        catch { }
        if (guid == "") guid = Environment.MachineName;
        return Convert.ToHexString(SHA256.HashData(Encoding.UTF8.GetBytes("zeiterfassung|" + guid))).ToLowerInvariant();
    }
}
