namespace Zeiterfassung;

/// <summary>Erststart: Server-URL, API-Key und Lizenzschlüssel eingeben und Gerät aktivieren.</summary>
public sealed class SetupForm : Form
{
    readonly AppSettings _s;
    readonly ApiClient _api;
    readonly TextBox _url = new() { Dock = DockStyle.Fill };
    readonly TextBox _apiKey = new() { Dock = DockStyle.Fill, UseSystemPasswordChar = true };
    readonly TextBox _license = new() { Dock = DockStyle.Fill, CharacterCasing = CharacterCasing.Upper };
    readonly Button _ok = new() { Text = "Aktivieren", Dock = DockStyle.Right, Width = 120 };
    readonly Label _msg = new() { Dock = DockStyle.Fill, ForeColor = Color.Firebrick, AutoSize = false };

    public SetupForm(AppSettings s, ApiClient api)
    {
        _s = s; _api = api;
        Text = "Zeiterfassung – Lizenzaktivierung";
        ClientSize = new Size(460, 250);
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = MinimizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;

        _url.Text = string.IsNullOrEmpty(s.ServerUrl) ? "https://zeiterfassung.gamingcommunity.at" : s.ServerUrl;

        var t = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 5, Padding = new Padding(14) };
        t.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 120));
        t.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        void Row(string label, Control c, int r)
        {
            t.Controls.Add(new Label { Text = label, Anchor = AnchorStyles.Left, AutoSize = true }, 0, r);
            t.Controls.Add(c, 1, r);
        }
        Row("Server-URL", _url, 0);
        Row("API-Key", _apiKey, 1);
        Row("Lizenzschlüssel", _license, 2);
        t.Controls.Add(_msg, 0, 3); t.SetColumnSpan(_msg, 2);
        var bar = new Panel { Dock = DockStyle.Fill }; bar.Controls.Add(_ok);
        t.Controls.Add(bar, 0, 4); t.SetColumnSpan(bar, 2);
        Controls.Add(t);

        AcceptButton = _ok;
        _ok.Click += async (_, _) => await ActivateAsync();
    }

    async Task ActivateAsync()
    {
        _msg.Text = "";
        var url = _url.Text.Trim();
        if (!Uri.TryCreate(url, UriKind.Absolute, out var uri) || (uri.Scheme != "https" && uri.Scheme != "http"))
        { _msg.Text = "Bitte eine gültige Server-URL angeben (https://…)."; return; }
        if (_apiKey.Text.Trim() == "" || _license.Text.Trim() == "")
        { _msg.Text = "API-Key und Lizenzschlüssel sind erforderlich."; return; }

        _ok.Enabled = false;
        try
        {
            _s.ServerUrl = url.TrimEnd('/');
            _s.ApiKey = _apiKey.Text.Trim();
            _s.Token = "";
            var info = await _api.ActivateAsync(_license.Text.Trim());
            _s.Activated = true;
            _s.Save();
            MessageBox.Show($"Lizenz aktiviert für: {info.Customer}\nGültig bis: {info.ExpiresAt ?? "unbegrenzt"}",
                "Aktivierung erfolgreich", MessageBoxButtons.OK, MessageBoxIcon.Information);
            DialogResult = DialogResult.OK;
        }
        catch (ApiException ex) { _msg.Text = ex.Message; }
        finally { _ok.Enabled = true; }
    }
}
