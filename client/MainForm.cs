using System.Diagnostics;

namespace Zeiterfassung;

public sealed class MainForm : Form
{
    readonly AppSettings _s;
    readonly ApiClient _api;

    readonly Label _state = new() { Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleCenter, Font = new Font("Segoe UI", 11f) };
    readonly Label _timer = new() { Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleCenter, Font = new Font("Segoe UI", 34f, FontStyle.Bold), Text = "0:00:00" };
    readonly ComboBox _project = new() { Dock = DockStyle.Fill, DropDownStyle = ComboBoxStyle.DropDownList };
    readonly TextBox _note = new() { Dock = DockStyle.Fill, PlaceholderText = "Notiz (optional)", MaxLength = 500 };
    readonly Button _in = new() { Text = "Kommen", Dock = DockStyle.Fill, BackColor = Color.SeaGreen, ForeColor = Color.White, FlatStyle = FlatStyle.Flat, Font = new Font("Segoe UI", 11f, FontStyle.Bold) };
    readonly Button _break = new() { Text = "Pause", Dock = DockStyle.Fill, FlatStyle = FlatStyle.System };
    readonly Button _out = new() { Text = "Gehen", Dock = DockStyle.Fill, BackColor = Color.Firebrick, ForeColor = Color.White, FlatStyle = FlatStyle.Flat, Font = new Font("Segoe UI", 11f, FontStyle.Bold) };
    readonly Label _today = new() { Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleLeft };
    readonly Label _week = new() { Dock = DockStyle.Fill, TextAlign = ContentAlignment.MiddleLeft };
    readonly ListView _list = new() { Dock = DockStyle.Fill, View = View.Details, FullRowSelect = true, GridLines = true };
    readonly System.Windows.Forms.Timer _tick = new() { Interval = 1000 };
    readonly NotifyIcon _tray = new() { Icon = SystemIcons.Application, Text = "Zeiterfassung" };

    StatusDto? _status;
    readonly Stopwatch _sinceSync = new();
    int _syncCounter;
    bool _busy, _reallyExit, _updating;
    int _updateCounter;
    string? _declinedVersion;

    public bool RestartRequested { get; private set; }

    public MainForm(AppSettings s, ApiClient api)
    {
        _s = s; _api = api;
        Text = "Zeiterfassung " + UpdateService.Current;
        ClientSize = new Size(560, 620);
        MinimumSize = new Size(520, 560);
        StartPosition = FormStartPosition.CenterScreen;

        var root = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 6, Padding = new Padding(12) };
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 30));   // state
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 70));   // timer
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 68));   // project + note
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 56));   // buttons
        root.RowStyles.Add(new RowStyle(SizeType.Absolute, 52));   // sums
        root.RowStyles.Add(new RowStyle(SizeType.Percent, 100));   // list

        var pn = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 2 };
        pn.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 70));
        pn.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        pn.Controls.Add(new Label { Text = "Projekt", Anchor = AnchorStyles.Left, AutoSize = true }, 0, 0);
        pn.Controls.Add(_project, 1, 0);
        pn.Controls.Add(new Label { Text = "Notiz", Anchor = AnchorStyles.Left, AutoSize = true }, 0, 1);
        pn.Controls.Add(_note, 1, 1);

        var btns = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 3, RowCount = 1 };
        for (int i = 0; i < 3; i++) btns.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 33.33f));
        _in.Margin = _break.Margin = _out.Margin = new Padding(4);
        btns.Controls.Add(_in, 0, 0); btns.Controls.Add(_break, 1, 0); btns.Controls.Add(_out, 2, 0);

        var sums = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 2 };
        sums.Controls.Add(_today, 0, 0); sums.Controls.Add(_week, 0, 1);

        _list.Columns.Add("Datum", 90);
        _list.Columns.Add("Kommen", 60);
        _list.Columns.Add("Gehen", 60);
        _list.Columns.Add("Pause", 55);
        _list.Columns.Add("Arbeitszeit", 80);
        _list.Columns.Add("Projekt", 100);
        _list.Columns.Add("Notiz", 200);

        root.Controls.Add(_state, 0, 0);
        root.Controls.Add(_timer, 0, 1);
        root.Controls.Add(pn, 0, 2);
        root.Controls.Add(btns, 0, 3);
        root.Controls.Add(sums, 0, 4);
        root.Controls.Add(_list, 0, 5);
        Controls.Add(root);

        // Menü
        var menu = new MenuStrip();
        var acc = new ToolStripMenuItem("Konto");
        acc.DropDownItems.Add("Aktualisieren", null, async (_, _) => await RefreshAllAsync());
        acc.DropDownItems.Add("Nach Updates suchen…", null, async (_, _) => await CheckForUpdatesAsync(true));
        acc.DropDownItems.Add("Einstellungen…", null, (_, _) => { using var f = new SettingsForm(_s); f.ShowDialog(this); });
        acc.DropDownItems.Add(new ToolStripSeparator());
        acc.DropDownItems.Add("Abmelden", null, async (_, _) => await LogoutAsync(false));
        acc.DropDownItems.Add("Lizenz ändern…", null, async (_, _) => await LogoutAsync(true));
        acc.DropDownItems.Add(new ToolStripSeparator());
        acc.DropDownItems.Add("Beenden", null, (_, _) => { _reallyExit = true; Close(); });
        menu.Items.Add(acc);
        MainMenuStrip = menu;
        Controls.Add(menu);

        _in.Click += async (_, _) => await RunAsync(() => _api.ClockInAsync((_project.SelectedItem as ProjectDto)?.Id, _note.Text));
        _out.Click += async (_, _) =>
        {
            if (MessageBox.Show("Jetzt ausstempeln?", "Gehen", MessageBoxButtons.YesNo, MessageBoxIcon.Question) == DialogResult.Yes)
                await RunAsync(() => _api.ClockOutAsync());
        };
        _break.Click += async (_, _) => await RunAsync(() => _status?.OnBreak == true ? _api.BreakEndAsync() : _api.BreakStartAsync());

        // Tray
        var trayMenu = new ContextMenuStrip();
        trayMenu.Items.Add("Öffnen", null, (_, _) => ShowFromTray());
        trayMenu.Items.Add("Beenden", null, (_, _) => { _reallyExit = true; Close(); });
        _tray.ContextMenuStrip = trayMenu;
        _tray.DoubleClick += (_, _) => ShowFromTray();
        _tray.Visible = true;

        Resize += (_, _) => { if (WindowState == FormWindowState.Minimized) Hide(); };
        FormClosing += OnClosing;
        _tick.Tick += async (_, _) => await OnTickAsync();
        Shown += async (_, _) => { await RefreshAllAsync(); _tick.Start(); await CheckForUpdatesAsync(false); };
    }

    void ShowFromTray() { Show(); WindowState = FormWindowState.Normal; Activate(); }

    void OnClosing(object? sender, FormClosingEventArgs e)
    {
        // X-Button minimiert in den Infobereich, damit die Zeit weiterläuft und man sie sieht
        if (!_reallyExit && !RestartRequested && e.CloseReason == CloseReason.UserClosing)
        {
            e.Cancel = true;
            Hide();
            _tray.ShowBalloonTip(2000, "Zeiterfassung", "Läuft weiter im Infobereich.", ToolTipIcon.Info);
            return;
        }
        _tick.Stop();
        _tray.Visible = false;
        _tray.Dispose();
    }

    async Task LogoutAsync(bool changeLicense)
    {
        if (_status?.ClockedIn == true &&
            MessageBox.Show("Du bist noch eingestempelt (die Zeit läuft weiter). Trotzdem abmelden?", "Abmelden",
                MessageBoxButtons.YesNo, MessageBoxIcon.Warning) != DialogResult.Yes) return;
        await _api.LogoutAsync();
        _s.Token = "";
        if (changeLicense) _s.Activated = false;
        _s.Save();
        RestartRequested = true;
        Close();
    }

    async Task OnTickAsync()
    {
        Render();
        if (++_syncCounter >= 30) { _syncCounter = 0; await RefreshStatusAsync(); }
        if (++_updateCounter >= 6 * 3600) { _updateCounter = 0; await CheckForUpdatesAsync(false); }
    }

    async Task RefreshAllAsync()
    {
        try
        {
            var projects = await _api.GetProjectsAsync();
            var sel = (_project.SelectedItem as ProjectDto)?.Id;
            _project.Items.Clear();
            _project.Items.Add(new ProjectDto(0, "– kein Projekt –"));
            foreach (var p in projects) _project.Items.Add(p);
            _project.SelectedItem = _project.Items.OfType<ProjectDto>().FirstOrDefault(p => p.Id == sel) ?? _project.Items[0];
        }
        catch (ApiException ex) { if (!HandleAuth(ex)) SetError(ex.Message); }
        await RefreshStatusAsync();
        await RefreshListAsync();
    }

    async Task RefreshStatusAsync()
    {
        if (_busy) return;
        try { ApplyStatus(await _api.GetStatusAsync()); }
        catch (ApiException ex) { if (!HandleAuth(ex)) SetError(ex.Message); }
    }

    async Task RefreshListAsync()
    {
        try
        {
            var entries = await _api.GetEntriesAsync(DateTime.Today.AddDays(-14), DateTime.Today);
            _list.BeginUpdate();
            _list.Items.Clear();
            foreach (var e in entries)
            {
                var start = DateTime.Parse(e.Start);
                _list.Items.Add(new ListViewItem(new[]
                {
                    start.ToString("ddd dd.MM."), start.ToString("HH:mm"),
                    e.End != null ? DateTime.Parse(e.End).ToString("HH:mm") : "läuft",
                    Fmt(e.BreakSeconds), Fmt(e.WorkedSeconds), e.ProjectName ?? "", e.Note,
                }));
            }
            _list.EndUpdate();
        }
        catch (ApiException ex) { if (!HandleAuth(ex)) SetError(ex.Message); }
    }

    async Task RunAsync(Func<Task<StatusDto>> action)
    {
        if (_busy) return;
        _busy = true;
        SetButtons(false, false, false);
        try
        {
            ApplyStatus(await action());
            _note.Clear();
            await RefreshListAsync();
        }
        catch (ApiException ex)
        {
            if (!HandleAuth(ex)) MessageBox.Show(ex.Message, "Zeiterfassung", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            _busy = false;
            await RefreshStatusAsync();
        }
        finally { _busy = false; Render(); }
    }

    /// <summary>true = Fehler war ein Lizenz-/Login-Problem und wurde behandelt (Neustart des Login-Ablaufs).</summary>
    bool HandleAuth(ApiException ex)
    {
        if (!ex.IsAuthProblem && !ex.IsLicenseProblem) return false;
        if (ex.IsLicenseProblem) { _s.Activated = false; }
        _s.Token = "";
        _s.Save();
        _tick.Stop();
        MessageBox.Show(ex.Message, "Zeiterfassung", MessageBoxButtons.OK, MessageBoxIcon.Warning);
        RestartRequested = true;
        Close();
        return true;
    }

    /// <summary>Prüft auf Updates. manual = vom Benutzer ausgelöst (fragt immer nach, meldet auch „aktuell“).</summary>
    async Task CheckForUpdatesAsync(bool manual)
    {
        if (_updating) return;
        UpdateInfo info;
        try { info = await _api.CheckUpdateAsync(UpdateService.Current); }
        catch (ApiException ex)
        {
            if (HandleAuth(ex)) return;
            if (manual) MessageBox.Show(ex.Message, "Update", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }

        if (!info.UpdateAvailable || info.Version is null)
        {
            if (manual) MessageBox.Show($"Du hast die aktuelle Version ({UpdateService.Current}).", "Update", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }

        var mode = manual ? (info.Mandatory ? UpdateMode.Auto : UpdateMode.Notify) : UpdateService.EffectiveMode(_s, info);
        if (mode == UpdateMode.Off) return;
        if (!manual && mode == UpdateMode.Notify && _declinedVersion == info.Version) return;

        if (!UpdateService.CanWriteAppDir())
        {
            if (manual || info.Mandatory)
                MessageBox.Show($"Version {info.Version} ist verfügbar, aber der Programmordner ist nicht beschreibbar.\n" +
                    "Bitte das Programm in einen Benutzerordner (z. B. %LocalAppData%) verschieben oder als Administrator ausführen.",
                    "Update", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }

        if (mode == UpdateMode.Notify)
        {
            var notes = string.IsNullOrWhiteSpace(info.Notes) ? "" : "\n\n" + info.Notes;
            var r = MessageBox.Show($"Version {info.Version} ist verfügbar (installiert: {UpdateService.Current}).{notes}\n\nJetzt installieren?",
                "Update verfügbar", MessageBoxButtons.YesNo, MessageBoxIcon.Information);
            if (r != DialogResult.Yes) { _declinedVersion = info.Version; return; }
        }
        else
        {
            _tray.ShowBalloonTip(3000, "Zeiterfassung", (info.Mandatory ? "Pflicht-Update" : "Update") + $" auf {info.Version} wird installiert …", ToolTipIcon.Info);
        }
        await InstallUpdateAsync(info);
    }

    async Task InstallUpdateAsync(UpdateInfo info)
    {
        _updating = true;
        SetButtons(false, false, false);
        _state.Text = "Update wird geladen …";
        try
        {
            var dir = await UpdateService.DownloadAndStageAsync(_api, info, new Progress<int>(p => _state.Text = $"Update wird geladen … {p} %"));
            _state.Text = "Update wird installiert – Zeiterfassung startet neu …";
            UpdateService.LaunchApplier(dir);
            _reallyExit = true; // Die Zeiterfassung läuft serverseitig weiter, ein Neustart unterbricht sie nicht
            Close();
        }
        catch (Exception ex)
        {
            _updating = false;
            MessageBox.Show("Update fehlgeschlagen: " + ex.Message, "Update", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            Render();
        }
    }

    void ApplyStatus(StatusDto st)
    {
        _status = st;
        _sinceSync.Restart();
        Render();
    }

    void SetError(string msg) => _state.Text = "⚠ " + msg;

    void SetButtons(bool cin, bool brk, bool cout)
    {
        _in.Enabled = cin; _break.Enabled = brk; _out.Enabled = cout;
        _project.Enabled = _note.Enabled = cin;
    }

    void Render()
    {
        if (_updating || _status is not { } st) return;
        long add = _sinceSync.IsRunning ? _sinceSync.ElapsedMilliseconds / 1000 : 0;
        bool running = st.ClockedIn && !st.OnBreak;
        long cur = st.CurrentSeconds + (running ? add : 0);
        long today = st.TodaySeconds + (running ? add : 0);
        long week = st.WeekSeconds + (running ? add : 0);

        _timer.Text = $"{cur / 3600}:{cur % 3600 / 60:00}:{cur % 60:00}";
        _timer.ForeColor = st.OnBreak ? Color.DarkOrange : st.ClockedIn ? Color.SeaGreen : SystemColors.GrayText;
        _state.Text = !st.ClockedIn ? "Nicht eingestempelt"
            : (st.OnBreak ? "In Pause" : "Eingestempelt") + " seit " + DateTime.Parse(st.Entry!.Start).ToString("HH:mm")
              + (st.Entry.ProjectName != null ? " · " + st.Entry.ProjectName : "");
        _today.Text = "Heute: " + Fmt(today) + " h";
        _week.Text = $"Woche: {Fmt(week)} h von {Fmt(st.WeekTargetSeconds)} h Soll";
        _break.Text = st.OnBreak ? "Pause beenden" : "Pause";
        if (!_busy) SetButtons(!st.ClockedIn, st.ClockedIn, st.ClockedIn);
        _tray.Text = ("Zeiterfassung – " + (st.ClockedIn ? (st.OnBreak ? "Pause" : _timer.Text) : "aus"));
    }

    static string Fmt(long sec) => $"{sec / 3600}:{sec % 3600 / 60:00}";
}
