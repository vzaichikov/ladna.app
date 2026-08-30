using Ladna.BattleMeter.Core;

namespace Ladna.BattleMeter.Tests;

[TestClass]
public sealed class OperatorSessionTests
{
    [TestMethod]
    public void CompleteFlowTransitionsToReadyToSubmit()
    {
        var session = new OperatorSession();
        session.SelectMatch(42);
        Assert.AreEqual(OperatorState.NeedsBaseline, session.State);

        session.BeginBaseline();
        session.CompleteBaseline(Statistics());
        session.BeginCountdown(PerformerSide.A);
        session.BeginPerformerCapture();
        session.CompletePerformerCapture(Statistics());
        session.BeginCountdown(PerformerSide.B);
        session.BeginPerformerCapture();
        session.CompletePerformerCapture(Statistics());

        Assert.AreEqual(OperatorState.ReadyToSubmit, session.State);
    }

    [TestMethod]
    public void ConfirmedRetakeClearsOnlySelectedPerformer()
    {
        var session = CompletedCaptureSession();

        session.Retake(PerformerSide.A);

        Assert.IsNull(session.CaptureA);
        Assert.IsNotNull(session.CaptureB);
        Assert.IsNotNull(session.Baseline);
        Assert.AreEqual(OperatorState.ReadyForCapture, session.State);
    }

    [TestMethod]
    public void MicrophoneChangeClearsBaselineAndBothCaptures()
    {
        var session = CompletedCaptureSession();

        session.ChangeMicrophone();

        Assert.IsNull(session.Baseline);
        Assert.IsNull(session.CaptureA);
        Assert.IsNull(session.CaptureB);
        Assert.AreEqual(OperatorState.NeedsBaseline, session.State);
    }

    [TestMethod]
    public void MatchChangeClearsAllMeasurements()
    {
        var session = CompletedCaptureSession();

        session.SelectMatch(99);

        Assert.AreEqual(99L, session.MatchId);
        Assert.IsNull(session.Baseline);
        Assert.IsNull(session.CaptureA);
        Assert.IsNull(session.CaptureB);
    }

    [TestMethod]
    public void DeviceFailureReturnsToCaptureReadyWithoutInventingAResult()
    {
        var session = new OperatorSession();
        session.SelectMatch(42);
        session.BeginBaseline();
        session.CompleteBaseline(Statistics());
        session.BeginCountdown(PerformerSide.A);
        session.BeginPerformerCapture();

        session.CaptureFailed();

        Assert.AreEqual(OperatorState.ReadyForCapture, session.State);
        Assert.IsNull(session.CaptureA);
    }

    [TestMethod]
    public void BaselineRetakeClearsBothPerformers()
    {
        var session = CompletedCaptureSession();

        session.BeginBaseline();

        Assert.IsNull(session.Baseline);
        Assert.IsNull(session.CaptureA);
        Assert.IsNull(session.CaptureB);
        Assert.AreEqual(OperatorState.CapturingBaseline, session.State);
    }

    [TestMethod]
    public void SubmissionMovesThroughWaitingTieAndOfficialWinnerStates()
    {
        var session = CompletedCaptureSession();

        session.BeginSubmission();
        session.ApplySubmissionState(SubmissionState.WaitingForJudges);
        Assert.AreEqual(OperatorState.WaitingForJudges, session.State);

        session.ApplySubmissionState(SubmissionState.WaitingForJuryDecision);
        Assert.AreEqual(OperatorState.WaitingForJuryDecision, session.State);

        session.ApplySubmissionState(SubmissionState.Completed);
        Assert.AreEqual(OperatorState.Completed, session.State);
    }

    private static OperatorSession CompletedCaptureSession()
    {
        var session = new OperatorSession();
        session.SelectMatch(42);
        session.BeginBaseline();
        session.CompleteBaseline(Statistics());

        foreach (var side in new[] { PerformerSide.A, PerformerSide.B })
        {
            session.BeginCountdown(side);
            session.BeginPerformerCapture();
            session.CompletePerformerCapture(Statistics());
        }

        return session;
    }

    private static CaptureStatistics Statistics()
    {
        return new CaptureStatistics(240_000, 0.1, Math.Sqrt(0.1), 0.5, -10, -6, 0, TimeSpan.FromSeconds(5));
    }
}
