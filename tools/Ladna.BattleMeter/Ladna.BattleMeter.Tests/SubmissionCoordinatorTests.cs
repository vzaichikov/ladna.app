using System.Net;
using Ladna.BattleMeter.Core;
using Ladna.BattleMeter.Models;
using Ladna.BattleMeter.Services;

namespace Ladna.BattleMeter.Tests;

[TestClass]
public sealed class SubmissionCoordinatorTests
{
    [TestMethod]
    public async Task WaitingForJudgesKeepsPayloadForIdempotentPolling()
    {
        var api = new FakeApiClient(Match("waiting_for_judges"));
        var coordinator = Prepared(api);

        var outcome = await coordinator.SubmitOrPollAsync(CancellationToken.None);

        Assert.AreEqual(SubmissionState.WaitingForJudges, outcome.State);
        Assert.IsTrue(outcome.ShouldPoll);
        Assert.IsTrue(coordinator.HasPendingSubmission);
    }

    [TestMethod]
    public async Task ExactTieWaitsForJuryDecisionAndKeepsPayload()
    {
        var coordinator = Prepared(new FakeApiClient(Match("jury_decision_required")));

        var outcome = await coordinator.SubmitOrPollAsync(CancellationToken.None);

        Assert.AreEqual(SubmissionState.WaitingForJuryDecision, outcome.State);
        Assert.IsTrue(coordinator.HasPendingSubmission);
    }

    [TestMethod]
    public async Task OfficialWinnerCompletesAndClearsPendingPayload()
    {
        var completed = WithWinner(Match("completed"));
        var coordinator = Prepared(new FakeApiClient(completed));

        var outcome = await coordinator.SubmitOrPollAsync(CancellationToken.None);

        Assert.AreEqual(SubmissionState.Completed, outcome.State);
        Assert.AreEqual("Performer A", outcome.Match?.Winner?.Name);
        Assert.IsFalse(coordinator.HasPendingSubmission);

    }

    [TestMethod]
    public async Task PollingRepeatsTheExactSameIdempotentPayload()
    {
        var api = new SequenceApiClient(Match("waiting_for_judges"), WithWinner(Match("completed")));
        var coordinator = Prepared(api);

        await coordinator.SubmitOrPollAsync(CancellationToken.None);
        var final = await coordinator.SubmitOrPollAsync(CancellationToken.None);

        Assert.AreEqual(SubmissionState.Completed, final.State);
        Assert.AreEqual(2, api.Submissions.Count);
        Assert.AreSame(api.Submissions[0], api.Submissions[1]);
    }

    [TestMethod]
    public async Task TimeoutKeepsPayloadInMemoryForRetry()
    {
        var coordinator = Prepared(new FakeApiClient(new TaskCanceledException("timeout")));

        var outcome = await coordinator.SubmitOrPollAsync(CancellationToken.None);

        Assert.AreEqual(SubmissionState.OfflineRetry, outcome.State);
        Assert.IsTrue(coordinator.HasPendingSubmission);
    }

    [DataTestMethod]
    [DataRow(401, SubmissionState.AuthenticationRequired)]
    [DataRow(402, SubmissionState.SubscriptionRequired)]
    [DataRow(403, SubmissionState.Forbidden)]
    [DataRow(404, SubmissionState.MatchUnavailable)]
    [DataRow(409, SubmissionState.Conflict)]
    [DataRow(422, SubmissionState.InvalidScore)]
    [DataRow(423, SubmissionState.Locked)]
    [DataRow(429, SubmissionState.RateLimited)]
    public async Task ApiFailuresMapToOperatorSafeStates(int status, SubmissionState expected)
    {
        var exception = new BattleApiException((HttpStatusCode)status, "failure");
        var coordinator = Prepared(new FakeApiClient(exception));

        var outcome = await coordinator.SubmitOrPollAsync(CancellationToken.None);

        Assert.AreEqual(expected, outcome.State);
        Assert.IsTrue(coordinator.HasPendingSubmission);
    }

    private static SubmissionCoordinator Prepared(IBattleApiClient api)
    {
        var coordinator = new SubmissionCoordinator(api);
        coordinator.Prepare(
            new Uri("https://ladna.example/"),
            "secret",
            42,
            new AudienceScoreSubmission { AudienceScoreA = 600_000, AudienceScoreB = 400_000 });
        return coordinator;
    }

    private static BattleMatch Match(string state)
    {
        return new BattleMatch
        {
            Id = 42,
            State = state,
            PerformerA = new BattlePerformer { Id = 1, Name = "Performer A" },
            PerformerB = new BattlePerformer { Id = 2, Name = "Performer B" },
        };
    }

    private static BattleMatch WithWinner(BattleMatch match)
    {
        return new BattleMatch
        {
            Id = match.Id,
            State = match.State,
            PerformerA = match.PerformerA,
            PerformerB = match.PerformerB,
            Winner = match.PerformerA,
        };
    }

    private sealed class FakeApiClient : IBattleApiClient
    {
        private readonly BattleMatch? _match;
        private readonly Exception? _exception;

        public FakeApiClient(BattleMatch match)
        {
            _match = match;
        }

        public FakeApiClient(Exception exception)
        {
            _exception = exception;
        }

        public Task<BattleListResponse> GetMatchesAsync(Uri server, string token, CancellationToken cancellationToken)
        {
            throw new NotSupportedException();
        }

        public Task<BattleMatch> GetMatchAsync(Uri server, string token, long matchId, CancellationToken cancellationToken)
        {
            throw new NotSupportedException();
        }

        public Task<BattleMatch> SubmitAudienceScoreAsync(
            Uri server,
            string token,
            long matchId,
            AudienceScoreSubmission submission,
            CancellationToken cancellationToken)
        {
            return _exception is not null
                ? Task.FromException<BattleMatch>(_exception)
                : Task.FromResult(_match!);
        }
    }

    private sealed class SequenceApiClient : IBattleApiClient
    {
        private readonly Queue<BattleMatch> _matches;

        public SequenceApiClient(params BattleMatch[] matches)
        {
            _matches = new Queue<BattleMatch>(matches);
        }

        public List<AudienceScoreSubmission> Submissions { get; } = [];

        public Task<BattleListResponse> GetMatchesAsync(Uri server, string token, CancellationToken cancellationToken)
        {
            throw new NotSupportedException();
        }

        public Task<BattleMatch> GetMatchAsync(Uri server, string token, long matchId, CancellationToken cancellationToken)
        {
            throw new NotSupportedException();
        }

        public Task<BattleMatch> SubmitAudienceScoreAsync(
            Uri server,
            string token,
            long matchId,
            AudienceScoreSubmission submission,
            CancellationToken cancellationToken)
        {
            Submissions.Add(submission);
            return Task.FromResult(_matches.Dequeue());
        }
    }
}
