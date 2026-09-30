namespace Zeiterfassung;

/// <summary>Benutzer-Login. DialogResult.Retry = Lizenz/Server ändern.</summary>
public sealed class LoginForm : Form
{
    readonly AppSettings _s;
    readonly ApiClient _api;
    readonly TextBox _user = new() { Dock = DockStyle.Fill };
    readonly TextBox _pass = new() { Dock = DockStyle.Fill, UseSystemPasswordChar = true };
    readonly Button _ok = new() { Text = "Anmelden", Width = 110 };
    readonly Button _change = new() { Text = "Lizenz ändern", Width = 110 };
    readonly Label _msg = new() { Dock = DockStyle.Fill, ForeColor = Color.Firebrick };

    public LoginForm(AppSettings s, ApiClient api)
    {
        _s = s; _api = api;
        Text = "Zeiterfassung – Anmelden";
        ClientSize = new Size(380, 200);
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = MinimizeBox = false;
        StartPosition = FormStartPosition.CenterScreen;

        _user.Text = s.LastUsername;

        var t = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 2, RowCount = 4, Padding = new Padding(14) };
        t.ColumnStyles.Add(new ColumnStyle(SizeType.Absolute, 100));
        t.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));
        t.Controls.Add(new Label { Text = "Benutzername", Anchor = AnchorStyles.Left, AutoSize = true }, 0, 0);
        t.Controls.Add(_user, 1, 0);
        t.Controls.Add(new Label { Text = "Passwort", Anchor = AnchorStyles.Left, AutoSize = true }, 0, 1);
        t.Controls.Add(_pass, 1, 1);
        t.Controls.Add(_msg, 0, 2); t.SetColumnSpan(_msg, 2);
        var bar = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.RightToLeft };
        bar.Controls.Add(_ok); bar.Controls.Add(_change);
        t.Controls.Add(bar, 0, 3); t.SetColumnSpan(bar, 2);
        Controls.Add(t);

        AcceptButton = _ok;
        _change.Click += (_, _) => DialogResult = DialogResult.Retry;
        _ok.Click += async (_, _) => await LoginAsync();
        Shown += (_, _) => (_user.Text == "" ? _user : _pass).Focus();
    }

    async Task LoginAsync()
    {
        _msg.Text = "";
        _ok.Enabled = false;
        try
        {
            var r = await _api.LoginAsync(_user.Text.Trim(), _pass.Text);
            _s.Token = r.Token;
            _s.LastUsername = _user.Text.Trim();
            _s.Save();
            DialogResult = DialogResult.OK;
        }
        catch (ApiException ex) when (ex.IsLicenseProblem)
        {
            _msg.Text = ex.Message + " – „Lizenz ändern“ verwenden.";
        }
        catch (ApiException ex) { _msg.Text = ex.Message; _pass.Clear(); }
        finally { _ok.Enabled = true; }
    }
}
