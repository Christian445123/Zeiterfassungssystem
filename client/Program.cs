namespace Zeiterfassung;

static class Program
{
    [STAThread]
    static void Main()
    {
        ApplicationConfiguration.Initialize();

        using var mutex = new Mutex(true, "Zeiterfassung.Client.SingleInstance", out bool first);
        if (!first)
        {
            MessageBox.Show("Zeiterfassung läuft bereits (siehe Infobereich der Taskleiste).", "Zeiterfassung");
            return;
        }

        var settings = AppSettings.Load();
        using var api = new ApiClient(settings);

        while (true)
        {
            // 1. Lizenz / Server / API-Key
            if (!settings.Activated || settings.ServerUrl == "" || settings.ApiKey == "")
            {
                using var setup = new SetupForm(settings, api);
                if (setup.ShowDialog() != DialogResult.OK) return;
            }

            // 2. Lizenz beim Start prüfen (offline = Login-Versuch zeigt Fehler)
            try { api.LicenseStatusAsync().GetAwaiter().GetResult(); }
            catch (ApiException ex) when (ex.IsLicenseProblem)
            {
                MessageBox.Show(ex.Message + "\n\nBitte Lizenz neu aktivieren.", "Lizenz", MessageBoxButtons.OK, MessageBoxIcon.Warning);
                settings.Activated = false;
                settings.Save();
                continue;
            }
            catch (ApiException ex)
            {
                MessageBox.Show(ex.Message, "Zeiterfassung", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            }

            // 3. Benutzer-Login (Token vorhanden -> direkt versuchen)
            UserDto? user = null;
            if (settings.Token != "")
            {
                try { api.GetStatusAsync().GetAwaiter().GetResult(); user = new UserDto(0, "", ""); }
                catch (ApiException) { settings.Token = ""; settings.Save(); }
            }
            if (user == null)
            {
                using var login = new LoginForm(settings, api);
                var res = login.ShowDialog();
                if (res == DialogResult.Retry) { settings.Activated = false; settings.Save(); continue; }
                if (res != DialogResult.OK) return;
            }

            // 4. Hauptfenster
            using var main = new MainForm(settings, api);
            Application.Run(main);
            if (main.RestartRequested) continue;
            return;
        }
    }
}
