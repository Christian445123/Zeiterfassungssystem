namespace Zeiterfassung;

/// <summary>Lokale Einstellungen: Update-Verhalten.</summary>
public sealed class SettingsForm : Form
{
    static readonly (UpdateMode Mode, string Text)[] Options =
    {
        (UpdateMode.Server, "Vorgabe des Servers verwenden (empfohlen)"),
        (UpdateMode.Auto, "Automatisch installieren"),
        (UpdateMode.Notify, "Nur benachrichtigen, ich entscheide"),
        (UpdateMode.Off, "Keine Updates (Pflicht-Updates trotzdem)"),
    };

    readonly ComboBox _mode = new() { Dock = DockStyle.Fill, DropDownStyle = ComboBoxStyle.DropDownList };

    public SettingsForm(AppSettings s)
    {
        Text = "Einstellungen";
        ClientSize = new Size(440, 170);
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = MinimizeBox = false;
        StartPosition = FormStartPosition.CenterParent;

        foreach (var o in Options) _mode.Items.Add(o.Text);
        _mode.SelectedIndex = Array.FindIndex(Options, o => o.Mode == s.UpdateMode);

        var ok = new Button { Text = "Speichern", DialogResult = DialogResult.OK, Width = 100 };
        var cancel = new Button { Text = "Abbrechen", DialogResult = DialogResult.Cancel, Width = 100 };
        var bar = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.RightToLeft };
        bar.Controls.Add(cancel); bar.Controls.Add(ok);

        var t = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, RowCount = 4, Padding = new Padding(14) };
        t.Controls.Add(new Label { Text = $"Installierte Version: {UpdateService.Current}", AutoSize = true }, 0, 0);
        t.Controls.Add(new Label { Text = "Updates", AutoSize = true, Margin = new Padding(3, 12, 3, 2) }, 0, 1);
        t.Controls.Add(_mode, 0, 2);
        t.Controls.Add(bar, 0, 3);
        Controls.Add(t);
        AcceptButton = ok; CancelButton = cancel;

        FormClosing += (_, _) =>
        {
            if (DialogResult == DialogResult.OK && _mode.SelectedIndex >= 0)
            {
                s.UpdateMode = Options[_mode.SelectedIndex].Mode;
                s.Save();
            }
        };
    }
}
