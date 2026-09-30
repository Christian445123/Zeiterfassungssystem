using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;

namespace Zeiterfassung;

public sealed record UserDto(int Id, string Name, string Role);
public sealed record LoginResult(string Token, UserDto User);
public sealed record ProjectDto(int Id, string Name)
{
    public override string ToString() => Name;
}
public sealed record CurrentEntryDto(int Id, string Start, int? ProjectId, string? ProjectName, string Note);
public sealed record StatusDto(bool ClockedIn, bool OnBreak, CurrentEntryDto? Entry, string? BreakStart,
    long CurrentSeconds, long TodaySeconds, long WeekSeconds, long WeekTargetSeconds, string ServerTime);
public sealed record EntryDto(int Id, string Start, string? End, string? ProjectName, string Note, long BreakSeconds, long WorkedSeconds);
public sealed record LicenseInfo(string Customer, string? ExpiresAt);

public sealed class ApiException : Exception
{
    public int StatusCode { get; }
    public string Code { get; }
    public ApiException(int status, string code, string message) : base(message) { StatusCode = status; Code = code; }

    /// <summary>Lizenz/Gerät/API-Key nicht (mehr) gültig -> Neu-Aktivierung nötig.</summary>
    public bool IsLicenseProblem => Code is "license_invalid" or "device_not_activated" or "api_key_invalid" or "api_key_missing";
    public bool IsAuthProblem => Code == "not_logged_in";
}

public sealed class ApiClient : IDisposable
{
    static readonly JsonSerializerOptions Json = new()
    {
        PropertyNamingPolicy = JsonNamingPolicy.SnakeCaseLower,
        PropertyNameCaseInsensitive = true,
    };

    readonly HttpClient _http = new() { Timeout = TimeSpan.FromSeconds(15) };
    readonly AppSettings _s;
    readonly string _machineId = AppSettings.MachineId();

    public ApiClient(AppSettings settings) => _s = settings;

    async Task<JsonElement> SendAsync(HttpMethod method, string route, object? body = null, string? query = null)
    {
        var url = $"{_s.ServerUrl.TrimEnd('/')}/api/index.php?route={route}{(query != null ? "&" + query : "")}";
        using var req = new HttpRequestMessage(method, url);
        req.Headers.Add("X-Api-Key", _s.ApiKey);
        req.Headers.Add("X-Machine-Id", _machineId);
        if (_s.Token != "") req.Headers.Add("X-Auth-Token", _s.Token);
        if (body != null)
            req.Content = new StringContent(JsonSerializer.Serialize(body, Json), Encoding.UTF8, new MediaTypeHeaderValue("application/json"));

        HttpResponseMessage resp;
        try { resp = await _http.SendAsync(req); }
        catch (Exception ex) when (ex is HttpRequestException or TaskCanceledException)
        {
            throw new ApiException(0, "network", "Server nicht erreichbar. Bitte Internetverbindung/Server-URL prüfen.");
        }

        using (resp)
        {
            var text = await resp.Content.ReadAsStringAsync();
            JsonDocument doc;
            try { doc = JsonDocument.Parse(text); }
            catch { throw new ApiException((int)resp.StatusCode, "bad_response", $"Ungültige Server-Antwort (HTTP {(int)resp.StatusCode})."); }

            var root = doc.RootElement.Clone();
            doc.Dispose();
            if (!resp.IsSuccessStatusCode || !(root.TryGetProperty("ok", out var ok) && ok.GetBoolean()))
            {
                var msg = root.TryGetProperty("error", out var e) ? e.GetString() : "Unbekannter Fehler";
                var code = root.TryGetProperty("code", out var c) ? c.GetString() : "error";
                throw new ApiException((int)resp.StatusCode, code ?? "error", msg ?? "Fehler");
            }
            return root;
        }
    }

    static T Get<T>(JsonElement root, string prop) => root.GetProperty(prop).Deserialize<T>(Json)!;

    public async Task<LicenseInfo> ActivateAsync(string licenseKey)
    {
        var r = await SendAsync(HttpMethod.Post, "license/activate", new { license_key = licenseKey, machine_name = Environment.MachineName });
        return new LicenseInfo(r.GetProperty("customer").GetString() ?? "",
            r.TryGetProperty("expires_at", out var e) && e.ValueKind == JsonValueKind.String ? e.GetString() : null);
    }

    public async Task<LicenseInfo> LicenseStatusAsync()
    {
        var r = await SendAsync(HttpMethod.Get, "license/status");
        return new LicenseInfo(r.GetProperty("customer").GetString() ?? "",
            r.TryGetProperty("expires_at", out var e) && e.ValueKind == JsonValueKind.String ? e.GetString() : null);
    }

    public async Task<LoginResult> LoginAsync(string username, string password)
    {
        var r = await SendAsync(HttpMethod.Post, "auth/login", new { username, password });
        return new LoginResult(r.GetProperty("token").GetString()!, Get<UserDto>(r, "user"));
    }

    public async Task LogoutAsync()
    {
        try { await SendAsync(HttpMethod.Post, "auth/logout", new { }); } catch { /* Token lokal ohnehin verworfen */ }
    }

    public async Task<StatusDto> GetStatusAsync() => Get<StatusDto>(await SendAsync(HttpMethod.Get, "status"), "status");
    public async Task<List<ProjectDto>> GetProjectsAsync() => Get<List<ProjectDto>>(await SendAsync(HttpMethod.Get, "projects"), "projects");

    public async Task<StatusDto> ClockInAsync(int? projectId, string note) =>
        Get<StatusDto>(await SendAsync(HttpMethod.Post, "clock/in", new { project_id = projectId, note }), "status");
    public async Task<StatusDto> ClockOutAsync() => Get<StatusDto>(await SendAsync(HttpMethod.Post, "clock/out", new { }), "status");
    public async Task<StatusDto> BreakStartAsync() => Get<StatusDto>(await SendAsync(HttpMethod.Post, "break/start", new { }), "status");
    public async Task<StatusDto> BreakEndAsync() => Get<StatusDto>(await SendAsync(HttpMethod.Post, "break/end", new { }), "status");

    public async Task<List<EntryDto>> GetEntriesAsync(DateTime from, DateTime to) =>
        Get<List<EntryDto>>(await SendAsync(HttpMethod.Get, "entries", null, $"from={from:yyyy-MM-dd}&to={to:yyyy-MM-dd}"), "entries");

    public void Dispose() => _http.Dispose();
}
