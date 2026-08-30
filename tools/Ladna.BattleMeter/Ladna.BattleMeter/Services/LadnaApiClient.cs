using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.IO;
using System.Text.Json;
using Ladna.BattleMeter.Models;

namespace Ladna.BattleMeter.Services;

public interface IBattleApiClient
{
    Task<BattleListResponse> GetMatchesAsync(Uri server, string token, CancellationToken cancellationToken);

    Task<BattleMatch> GetMatchAsync(Uri server, string token, long matchId, CancellationToken cancellationToken);

    Task<BattleMatch> SubmitAudienceScoreAsync(
        Uri server,
        string token,
        long matchId,
        AudienceScoreSubmission submission,
        CancellationToken cancellationToken);
}

public sealed class BattleApiException : Exception
{
    public BattleApiException(HttpStatusCode statusCode, string message, string? errorCode = null)
        : base(message)
    {
        StatusCode = statusCode;
        ErrorCode = errorCode;
    }

    public HttpStatusCode StatusCode { get; }

    public string? ErrorCode { get; }
}

public sealed class LadnaApiClient : IBattleApiClient, IDisposable
{
    private static readonly JsonSerializerOptions JsonOptions = new(JsonSerializerDefaults.Web);
    private readonly HttpClient _httpClient;
    private readonly bool _ownsClient;

    public LadnaApiClient(HttpClient? httpClient = null)
    {
        _ownsClient = httpClient is null;
        _httpClient = httpClient ?? new HttpClient { Timeout = TimeSpan.FromSeconds(15) };
    }

    public async Task<BattleListResponse> GetMatchesAsync(
        Uri server,
        string token,
        CancellationToken cancellationToken)
    {
        using var request = CreateRequest(HttpMethod.Get, server, "api/v1/festival-battles/matches", token);
        using var response = await _httpClient.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
        return await ReadResponseAsync<BattleListResponse>(response, cancellationToken);
    }

    public async Task<BattleMatch> GetMatchAsync(
        Uri server,
        string token,
        long matchId,
        CancellationToken cancellationToken)
    {
        using var request = CreateRequest(HttpMethod.Get, server, $"api/v1/festival-battles/matches/{matchId}", token);
        using var response = await _httpClient.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
        return (await ReadResponseAsync<BattleResponse>(response, cancellationToken)).Data;
    }

    public async Task<BattleMatch> SubmitAudienceScoreAsync(
        Uri server,
        string token,
        long matchId,
        AudienceScoreSubmission submission,
        CancellationToken cancellationToken)
    {
        using var request = CreateRequest(HttpMethod.Put, server, $"api/v1/festival-battles/matches/{matchId}/audience-score", token);
        request.Content = JsonContent.Create(submission, options: JsonOptions);
        using var response = await _httpClient.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, cancellationToken);
        return (await ReadResponseAsync<BattleResponse>(response, cancellationToken)).Data;
    }

    public void Dispose()
    {
        if (_ownsClient)
        {
            _httpClient.Dispose();
        }
    }

    public static Uri ValidateServer(string value)
    {
        if (!Uri.TryCreate(value.Trim(), UriKind.Absolute, out var server))
        {
            throw new ArgumentException("Enter a valid absolute Ladna server URL.");
        }

        if (server.Scheme != Uri.UriSchemeHttps && !(server.Scheme == Uri.UriSchemeHttp && server.IsLoopback))
        {
            throw new ArgumentException("Ladna API connections require HTTPS. HTTP is allowed only for loopback development.");
        }

        var builder = new UriBuilder(server)
        {
            Path = server.AbsolutePath.TrimEnd('/') + "/",
            Query = string.Empty,
            Fragment = string.Empty,
        };

        return builder.Uri;
    }

    private static HttpRequestMessage CreateRequest(HttpMethod method, Uri server, string path, string token)
    {
        if (string.IsNullOrWhiteSpace(token))
        {
            throw new ArgumentException("Enter an API token.");
        }

        var request = new HttpRequestMessage(method, new Uri(server, path));
        request.Headers.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", token.Trim());
        request.Headers.CacheControl = new CacheControlHeaderValue { NoCache = true, NoStore = true };
        return request;
    }

    private static async Task<T> ReadResponseAsync<T>(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        if (!response.IsSuccessStatusCode)
        {
            ApiErrorResponse? error = null;

            try
            {
                error = await response.Content.ReadFromJsonAsync<ApiErrorResponse>(JsonOptions, cancellationToken);
            }
            catch (JsonException)
            {
            }

            throw new BattleApiException(
                response.StatusCode,
                error?.Message ?? $"Ladna returned HTTP {(int)response.StatusCode}.",
                error?.Code);
        }

        var result = await response.Content.ReadFromJsonAsync<T>(JsonOptions, cancellationToken);
        return result ?? throw new InvalidDataException("Ladna returned an empty JSON response.");
    }
}
