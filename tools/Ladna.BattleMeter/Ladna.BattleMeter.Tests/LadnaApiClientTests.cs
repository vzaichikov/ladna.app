using System.Net;
using System.Text;
using Ladna.BattleMeter.Models;
using Ladna.BattleMeter.Services;

namespace Ladna.BattleMeter.Tests;

[TestClass]
public sealed class LadnaApiClientTests
{
    [TestMethod]
    public async Task ListParsesSafeContractAndAccountLocale()
    {
        const string json = """
            {
              "data": [{
                "id": 42,
                "edition_label": "Ladna Fest 2026",
                "category_label": "Open",
                "round_number": 2,
                "position": 1,
                "state": "ready",
                "performer_a": {"id": 10, "name": "Alpha"},
                "performer_b": {"id": 11, "name": "Beta"},
                "judge_votes": {"required": 4, "submitted": 2, "a": 1, "b": 1},
                "audience": null,
                "combined": null,
                "winner": null
              }],
              "meta": {"locale": "uk", "poll_interval_seconds": 5}
            }
            """;
        var handler = new StubHandler((_, _) => Json(HttpStatusCode.OK, json));
        using var client = new LadnaApiClient(new HttpClient(handler));

        var response = await client.GetMatchesAsync(new Uri("https://ladna.example/"), "token", CancellationToken.None);

        Assert.AreEqual("uk", response.Meta.Locale);
        Assert.AreEqual(5, response.Meta.PollIntervalSeconds);
        Assert.AreEqual("Alpha", response.Data.Single().PerformerA?.Name);
    }

    [TestMethod]
    public async Task PutUsesBearerTokenAndSerializesScoresAndMetadata()
    {
        string? requestBody = null;
        var handler = new StubHandler(async (request, cancellationToken) =>
        {
            Assert.AreEqual(HttpMethod.Put, request.Method);
            Assert.AreEqual("https://ladna.example/api/v1/festival-battles/matches/42/audience-score", request.RequestUri?.ToString());
            Assert.AreEqual("Bearer", request.Headers.Authorization?.Scheme);
            Assert.AreEqual("secret", request.Headers.Authorization?.Parameter);
            Assert.IsTrue(request.Headers.CacheControl?.NoStore);
            requestBody = await request.Content!.ReadAsStringAsync(cancellationToken);

            return Json(HttpStatusCode.Accepted, """
                {"data":{"id":42,"state":"waiting_for_judges","performer_a":{"id":1,"name":"A"},"performer_b":{"id":2,"name":"B"},"judge_votes":{"required":4,"submitted":3,"a":2,"b":1}}}
                """);
        });
        using var client = new LadnaApiClient(new HttpClient(handler));

        var match = await client.SubmitAudienceScoreAsync(
            new Uri("https://ladna.example/"),
            "secret",
            42,
            new AudienceScoreSubmission
            {
                AudienceScoreA = 600_000,
                AudienceScoreB = 400_000,
                Measurement = new MeasurementMetadata
                {
                    BaselineDbfs = -52.1,
                    MeanDbfsA = -12.2,
                    MeanDbfsB = -15.4,
                    PeakDbfsA = -2.5,
                    PeakDbfsB = -3.2,
                },
            },
            CancellationToken.None);

        Assert.AreEqual("waiting_for_judges", match.State);
        StringAssert.Contains(requestBody, "\"audience_score_a\":600000");
        StringAssert.Contains(requestBody, "\"metric\":\"baseline_adjusted_integrated_energy\"");
        StringAssert.Contains(requestBody, "\"baseline_duration_ms\":2000");
        StringAssert.Contains(requestBody, "\"duration_ms\":5000");
    }

    [DataTestMethod]
    [DataRow(401)]
    [DataRow(402)]
    [DataRow(403)]
    [DataRow(404)]
    [DataRow(409)]
    [DataRow(422)]
    [DataRow(423)]
    [DataRow(429)]
    public async Task ErrorStatusesPreserveHttpCodeAndSafeMessage(int status)
    {
        var handler = new StubHandler((_, _) => Json((HttpStatusCode)status, "{\"message\":\"Safe API message\",\"code\":\"battle_error\"}"));
        using var client = new LadnaApiClient(new HttpClient(handler));

        var exception = await Assert.ThrowsExceptionAsync<BattleApiException>(() =>
            client.GetMatchesAsync(new Uri("https://ladna.example/"), "token", CancellationToken.None));

        Assert.AreEqual((HttpStatusCode)status, exception.StatusCode);
        Assert.AreEqual("Safe API message", exception.Message);
        Assert.AreEqual("battle_error", exception.ErrorCode);
    }

    [TestMethod]
    public async Task NetworkTimeoutRemainsDistinguishableForInMemoryRetry()
    {
        var handler = new StubHandler((_, _) => Task.FromException<HttpResponseMessage>(new TaskCanceledException("timeout")));
        using var client = new LadnaApiClient(new HttpClient(handler));

        await Assert.ThrowsExceptionAsync<TaskCanceledException>(() =>
            client.GetMatchesAsync(new Uri("https://ladna.example/"), "token", CancellationToken.None));
    }

    [TestMethod]
    public void RemoteHttpServerIsRejectedButLoopbackHttpIsAllowed()
    {
        Assert.ThrowsException<ArgumentException>(() => LadnaApiClient.ValidateServer("http://ladna.example"));
        Assert.AreEqual("http://localhost:8080/", LadnaApiClient.ValidateServer("http://localhost:8080").ToString());
    }

    [TestMethod]
    public void InvalidCertificateAllowanceIsExplicitSessionScopedAndLocalOnly()
    {
        var localServer = LadnaApiClient.ValidateServer("https://ladna.local");
        var productionServer = LadnaApiClient.ValidateServer("https://ladna.app");
        using var client = new LadnaApiClient();

        Assert.IsTrue(LadnaApiClient.IsLocalDevelopmentServer(localServer));
        Assert.IsFalse(LadnaApiClient.IsLocalDevelopmentServer(productionServer));
        Assert.IsFalse(client.AllowsInvalidCertificateFor(localServer));

        client.AllowInvalidCertificateFor(localServer);

        Assert.IsTrue(client.AllowsInvalidCertificateFor(localServer));
        Assert.IsFalse(client.AllowsInvalidCertificateFor(productionServer));
        Assert.ThrowsException<InvalidOperationException>(() => client.AllowInvalidCertificateFor(productionServer));

        client.ClearInvalidCertificateAllowance();
        Assert.IsFalse(client.AllowsInvalidCertificateFor(localServer));
    }

    private static HttpResponseMessage Json(HttpStatusCode status, string json)
    {
        return new HttpResponseMessage(status)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json"),
        };
    }

    private sealed class StubHandler : HttpMessageHandler
    {
        private readonly Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> _handler;

        public StubHandler(Func<HttpRequestMessage, CancellationToken, HttpResponseMessage> handler)
            : this((request, cancellationToken) => Task.FromResult(handler(request, cancellationToken)))
        {
        }

        public StubHandler(Func<HttpRequestMessage, CancellationToken, Task<HttpResponseMessage>> handler)
        {
            _handler = handler;
        }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            return _handler(request, cancellationToken);
        }
    }
}
