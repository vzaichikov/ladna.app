namespace Ladna.BattleMeter.Core;

public sealed record CaptureStatistics(
    long SampleCount,
    double MeanEnergy,
    double RootMeanSquare,
    double Peak,
    double RelativeDbfs,
    double PeakDbfs,
    long ClippedSampleCount,
    TimeSpan Duration)
{
    public double ClippedSampleRatio => SampleCount == 0
        ? 0
        : ClippedSampleCount / (double)SampleCount;
}

public sealed record LiveLevel(double SmoothedDbfs, double PeakDbfs, double Progress);

public enum CaptureRejection
{
    None,
    NoSamples,
    Silent,
    Clipped,
    InsufficientAboveBaseline,
}

public sealed record CaptureValidation(CaptureRejection Rejection, string Message)
{
    public bool IsAccepted => Rejection == CaptureRejection.None;

    public static CaptureValidation Accepted { get; } = new(CaptureRejection.None, string.Empty);
}

public sealed record NormalizedAudienceScore(
    int ScoreA,
    int ScoreB,
    double AdjustedEnergyA,
    double AdjustedEnergyB);
