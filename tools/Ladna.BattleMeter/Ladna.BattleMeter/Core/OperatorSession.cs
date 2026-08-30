namespace Ladna.BattleMeter.Core;

public enum PerformerSide
{
    A,
    B,
}

public enum OperatorState
{
    NoMatch,
    NeedsBaseline,
    CapturingBaseline,
    ReadyForCapture,
    Countdown,
    CapturingPerformer,
    ReadyToSubmit,
    Submitting,
    WaitingForJudges,
    WaitingForJuryDecision,
    Completed,
    RecoverableError,
}

public sealed class OperatorSession
{
    public OperatorState State { get; private set; } = OperatorState.NoMatch;

    public long? MatchId { get; private set; }

    public CaptureStatistics? Baseline { get; private set; }

    public CaptureStatistics? CaptureA { get; private set; }

    public CaptureStatistics? CaptureB { get; private set; }

    public PerformerSide? ActiveSide { get; private set; }

    public void SelectMatch(long matchId)
    {
        MatchId = matchId;
        ResetMeasurements();
    }

    public void ChangeMicrophone()
    {
        ResetMeasurements();
    }

    public void ClearMatch()
    {
        MatchId = null;
        Baseline = null;
        CaptureA = null;
        CaptureB = null;
        ActiveSide = null;
        State = OperatorState.NoMatch;
    }

    public void BeginBaseline()
    {
        Require(
            State is OperatorState.NeedsBaseline or OperatorState.ReadyForCapture or OperatorState.ReadyToSubmit or OperatorState.RecoverableError,
            "A match must be ready for a baseline capture.");
        Baseline = null;
        CaptureA = null;
        CaptureB = null;
        State = OperatorState.CapturingBaseline;
    }

    public void CompleteBaseline(CaptureStatistics baseline)
    {
        Require(State == OperatorState.CapturingBaseline, "No baseline capture is active.");
        Baseline = baseline;
        CaptureA = null;
        CaptureB = null;
        State = OperatorState.ReadyForCapture;
    }

    public void BeginCountdown(PerformerSide side)
    {
        Require(Baseline is not null, "Capture the ambient baseline first.");
        Require(State is OperatorState.ReadyForCapture or OperatorState.ReadyToSubmit, "A performer capture cannot start now.");
        ActiveSide = side;
        State = OperatorState.Countdown;
    }

    public void BeginPerformerCapture()
    {
        Require(State == OperatorState.Countdown && ActiveSide is not null, "No performer countdown is active.");
        State = OperatorState.CapturingPerformer;
    }

    public void CompletePerformerCapture(CaptureStatistics capture)
    {
        Require(State == OperatorState.CapturingPerformer && ActiveSide is not null, "No performer capture is active.");

        if (ActiveSide == PerformerSide.A)
        {
            CaptureA = capture;
        }
        else
        {
            CaptureB = capture;
        }

        ActiveSide = null;
        State = CaptureA is not null && CaptureB is not null
            ? OperatorState.ReadyToSubmit
            : OperatorState.ReadyForCapture;
    }

    public void Retake(PerformerSide side)
    {
        Require(State is OperatorState.ReadyForCapture or OperatorState.ReadyToSubmit or OperatorState.RecoverableError, "A retake cannot start now.");

        if (side == PerformerSide.A)
        {
            CaptureA = null;
        }
        else
        {
            CaptureB = null;
        }

        State = OperatorState.ReadyForCapture;
    }

    public void CaptureFailed()
    {
        ActiveSide = null;
        State = Baseline is null ? OperatorState.NeedsBaseline : OperatorState.ReadyForCapture;
    }

    public void BeginSubmission()
    {
        Require(CaptureA is not null && CaptureB is not null, "Both performer captures are required.");
        State = OperatorState.Submitting;
    }

    public void ApplySubmissionState(SubmissionState state)
    {
        State = state switch
        {
            SubmissionState.WaitingForJudges => OperatorState.WaitingForJudges,
            SubmissionState.WaitingForJuryDecision => OperatorState.WaitingForJuryDecision,
            SubmissionState.Completed => OperatorState.Completed,
            _ => OperatorState.RecoverableError,
        };
    }

    private void ResetMeasurements()
    {
        Baseline = null;
        CaptureA = null;
        CaptureB = null;
        ActiveSide = null;
        State = MatchId is null ? OperatorState.NoMatch : OperatorState.NeedsBaseline;
    }

    private static void Require(bool condition, string message)
    {
        if (!condition)
        {
            throw new InvalidOperationException(message);
        }
    }
}
