using System.Net;
using System.Net.Http;
using Ladna.BattleMeter.Models;
using Ladna.BattleMeter.Services;

namespace Ladna.BattleMeter.Core;

public enum SubmissionState
{
    WaitingForJudges,
    WaitingForJuryDecision,
    Completed,
    AuthenticationRequired,
    SubscriptionRequired,
    Forbidden,
    MatchUnavailable,
    Conflict,
    InvalidScore,
    Locked,
    RateLimited,
    OfflineRetry,
    ServerError,
}

public sealed record SubmissionOutcome(SubmissionState State, BattleMatch? Match, string Message)
{
    public bool ShouldPoll => State is SubmissionState.WaitingForJudges or SubmissionState.WaitingForJuryDecision;

    public bool CanRetry => State is SubmissionState.OfflineRetry or SubmissionState.RateLimited or SubmissionState.ServerError;
}

public sealed class SubmissionCoordinator
{
    private readonly IBattleApiClient _apiClient;
    private Uri? _server;
    private string? _token;
    private long _matchId;

    public SubmissionCoordinator(IBattleApiClient apiClient)
    {
        _apiClient = apiClient;
    }

    public AudienceScoreSubmission? PendingSubmission { get; private set; }

    public bool HasPendingSubmission => PendingSubmission is not null;

    public void Prepare(Uri server, string token, long matchId, AudienceScoreSubmission submission)
    {
        _server = server;
        _token = token;
        _matchId = matchId;
        PendingSubmission = submission;
    }

    public void Clear()
    {
        _server = null;
        _token = null;
        _matchId = 0;
        PendingSubmission = null;
    }

    public async Task<SubmissionOutcome> SubmitOrPollAsync(CancellationToken cancellationToken)
    {
        if (_server is null || string.IsNullOrWhiteSpace(_token) || PendingSubmission is null || _matchId <= 0)
        {
            throw new InvalidOperationException("No audience score is pending.");
        }

        try
        {
            var match = await _apiClient.SubmitAudienceScoreAsync(
                _server,
                _token,
                _matchId,
                PendingSubmission,
                cancellationToken);

            var state = match.State switch
            {
                "completed" => SubmissionState.Completed,
                "jury_decision_required" => SubmissionState.WaitingForJuryDecision,
                _ => SubmissionState.WaitingForJudges,
            };

            if (state == SubmissionState.Completed)
            {
                PendingSubmission = null;
            }

            return new SubmissionOutcome(state, match, match.State);
        }
        catch (BattleApiException exception)
        {
            return new SubmissionOutcome(MapStatus(exception.StatusCode), null, exception.Message);
        }
        catch (TaskCanceledException) when (!cancellationToken.IsCancellationRequested)
        {
            return new SubmissionOutcome(SubmissionState.OfflineRetry, null, "The Ladna request timed out. The score is still in memory.");
        }
        catch (HttpRequestException exception)
        {
            return new SubmissionOutcome(SubmissionState.OfflineRetry, null, $"Ladna is temporarily unreachable: {exception.Message}");
        }
    }

    private static SubmissionState MapStatus(HttpStatusCode statusCode)
    {
        return statusCode switch
        {
            HttpStatusCode.Unauthorized => SubmissionState.AuthenticationRequired,
            HttpStatusCode.PaymentRequired => SubmissionState.SubscriptionRequired,
            HttpStatusCode.Forbidden => SubmissionState.Forbidden,
            HttpStatusCode.NotFound => SubmissionState.MatchUnavailable,
            HttpStatusCode.Conflict => SubmissionState.Conflict,
            HttpStatusCode.UnprocessableEntity => SubmissionState.InvalidScore,
            HttpStatusCode.Locked => SubmissionState.Locked,
            HttpStatusCode.TooManyRequests => SubmissionState.RateLimited,
            _ when (int)statusCode >= 500 => SubmissionState.ServerError,
            _ => SubmissionState.ServerError,
        };
    }
}
