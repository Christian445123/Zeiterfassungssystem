using System.Diagnostics;
using System.IO.Compression;
using System.Security.Cryptography;

namespace Zeiterfassung;

public enum UpdateMode { Server, Off, Notify, Auto }

public static class UpdateService
{
    const string ExeName = "Zeiterfassung.exe";

    public static Version Current
    {
        get
        {
            var v = typeof(UpdateService).Assembly.GetName().Version ?? new Version(1, 0, 0);
            return new Version(v.Major, v.Minor, Math.Max(v.Build, 0));
        }
    }

    static string AppDir => AppContext.BaseDirectory.TrimEnd(Path.DirectorySeparatorChar);
    static string UpdaterRoot => Path.Combine(Path.GetTempPath(), "ZeiterfassungUpdater");
    static string StageRoot => Path.Combine(
        Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "Zeiterfassung", "update");

    /// <summary>Wirksamer Modus: lokale Einstellung, bei „Server“ die Vorgabe des Panels; Pflicht-Updates immer automatisch.</summary>
    public static UpdateMode EffectiveMode(AppSettings s, UpdateInfo info)
    {
        var mode = s.UpdateMode;
        if (mode == UpdateMode.Server)
            mode = info.Policy switch { "auto" => UpdateMode.Auto, "off" => UpdateMode.Off, _ => UpdateMode.Notify };
        if (info.Mandatory) mode = UpdateMode.Auto;
        return mode;
    }

    public static bool CanWriteAppDir()
    {
        try
        {
            var f = Path.Combine(AppDir, ".w" + Guid.NewGuid().ToString("N"));
            File.WriteAllText(f, "");
            File.Delete(f);
            return true;
        }
        catch { return false; }
    }

    /// <summary>Lädt das Release, prüft die SHA-256-Summe und entpackt es. Rückgabe: Ordner mit den neuen Dateien.</summary>
    public static async Task<string> DownloadAndStageAsync(ApiClient api, UpdateInfo info, IProgress<int>? progress = null)
    {
        var ver = info.Version ?? throw new InvalidDataException("Keine Version angegeben.");
        Directory.CreateDirectory(StageRoot);
        var zip = Path.Combine(StageRoot, ver + ".zip");
        await api.DownloadUpdateAsync(ver, zip, progress);

        var hash = Convert.ToHexString(SHA256.HashData(await File.ReadAllBytesAsync(zip))).ToLowerInvariant();
        if (!string.Equals(hash, info.Sha256, StringComparison.OrdinalIgnoreCase))
        {
            File.Delete(zip);
            throw new InvalidDataException("Die Prüfsumme (SHA-256) des Updates stimmt nicht.");
        }

        var dir = Path.Combine(StageRoot, ver);
        if (Directory.Exists(dir)) Directory.Delete(dir, true);
        ZipFile.ExtractToDirectory(zip, dir);
        File.Delete(zip);
        if (!File.Exists(Path.Combine(dir, ExeName)))
            throw new InvalidDataException("Das Update-Paket ist ungültig (" + ExeName + " fehlt).");
        return dir;
    }

    /// <summary>
    /// Die laufende EXE kann sich nicht selbst überschreiben: Kopie der Anwendung in den Temp-Ordner starten,
    /// die wartet bis dieser Prozess beendet ist, ersetzt die Dateien und startet die neue Version.
    /// </summary>
    public static void LaunchApplier(string stageDir)
    {
        var tmp = Path.Combine(UpdaterRoot, Guid.NewGuid().ToString("N"));
        CopyDirectory(AppDir, tmp);
        var psi = new ProcessStartInfo(Path.Combine(tmp, ExeName)) { UseShellExecute = false, WorkingDirectory = tmp };
        psi.ArgumentList.Add("--apply-update");
        psi.ArgumentList.Add(stageDir);
        psi.ArgumentList.Add(AppDir);
        psi.ArgumentList.Add(Environment.ProcessId.ToString());
        Process.Start(psi);
    }

    /// <summary>Einstiegspunkt der Updater-Kopie (--apply-update). Bei Fehlern wird der alte Stand wiederhergestellt.</summary>
    public static int Apply(string stageDir, string appDir, int pid)
    {
        if (pid > 0)
        {
            try { using var p = Process.GetProcessById(pid); p.WaitForExit(30000); }
            catch (ArgumentException) { /* Prozess schon beendet */ }
        }

        var backup = Path.Combine(AppContext.BaseDirectory, "backup");
        var touched = new List<string>();
        try
        {
            foreach (var src in Directory.EnumerateFiles(stageDir, "*", SearchOption.AllDirectories))
            {
                var rel = Path.GetRelativePath(stageDir, src);
                var dst = Path.Combine(appDir, rel);
                Directory.CreateDirectory(Path.GetDirectoryName(dst)!);
                if (File.Exists(dst))
                {
                    var b = Path.Combine(backup, rel);
                    Directory.CreateDirectory(Path.GetDirectoryName(b)!);
                    File.Copy(dst, b, true);
                }
                touched.Add(rel);
                CopyWithRetry(src, dst);
            }
        }
        catch (Exception ex)
        {
            foreach (var rel in touched)
            {
                var b = Path.Combine(backup, rel);
                var dst = Path.Combine(appDir, rel);
                try { if (File.Exists(b)) File.Copy(b, dst, true); else File.Delete(dst); } catch { /* best effort */ }
            }
            MessageBox.Show("Das Update konnte nicht installiert werden – die alte Version wurde wiederhergestellt.\n\n" + ex.Message,
                "Zeiterfassung", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            StartApp(appDir);
            return 1;
        }

        try { Directory.Delete(stageDir, true); } catch { }
        StartApp(appDir);
        return 0;
    }

    /// <summary>Räumt Updater-Kopien früherer Updates auf (best effort).</summary>
    public static void CleanupOldUpdater()
    {
        try
        {
            foreach (var d in Directory.GetDirectories(UpdaterRoot))
                try { Directory.Delete(d, true); } catch { /* evtl. noch in Benutzung */ }
        }
        catch { }
    }

    static void StartApp(string appDir) =>
        Process.Start(new ProcessStartInfo(Path.Combine(appDir, ExeName)) { UseShellExecute = true, WorkingDirectory = appDir });

    static void CopyWithRetry(string src, string dst)
    {
        for (int i = 0; ; i++)
        {
            try { File.Copy(src, dst, true); return; }
            catch (IOException) when (i < 6) { Thread.Sleep(500); } // Datei noch gesperrt (z. B. Virenscanner)
        }
    }

    static void CopyDirectory(string from, string to)
    {
        Directory.CreateDirectory(to);
        foreach (var f in Directory.GetFiles(from)) File.Copy(f, Path.Combine(to, Path.GetFileName(f)), true);
        foreach (var d in Directory.GetDirectories(from)) CopyDirectory(d, Path.Combine(to, Path.GetFileName(d)));
    }
}
