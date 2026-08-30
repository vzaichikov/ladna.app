namespace Ladna.BattleMeter.Core;

public sealed class SignalAnalyzer
{
    public const int NormalizedTotal = 1_000_000;
    public const double EnergyFloor = 1e-12;
    private const double SilenceEnergy = 1e-8;
    private const double MinimumEnergyRatioAboveBaseline = 1.25892541179; // 1 dB.

    public CaptureValidation ValidateBaseline(CaptureStatistics baseline)
    {
        if (baseline.SampleCount == 0)
        {
            return new CaptureValidation(CaptureRejection.NoSamples, "No microphone samples were received.");
        }

        if (baseline.ClippedSampleCount > 0)
        {
            return new CaptureValidation(CaptureRejection.Clipped, "The microphone clipped. Reduce its gain and capture the baseline again.");
        }

        if (baseline.MeanEnergy <= SilenceEnergy)
        {
            return new CaptureValidation(CaptureRejection.Silent, "The microphone signal is silent. Check the selected device and its gain.");
        }

        return CaptureValidation.Accepted;
    }

    public CaptureValidation ValidateCrowd(CaptureStatistics capture, CaptureStatistics baseline)
    {
        if (capture.SampleCount == 0)
        {
            return new CaptureValidation(CaptureRejection.NoSamples, "No microphone samples were received.");
        }

        if (capture.ClippedSampleCount > 0)
        {
            return new CaptureValidation(CaptureRejection.Clipped, "The applause capture clipped. Reduce microphone gain and retake it.");
        }

        if (capture.MeanEnergy <= SilenceEnergy)
        {
            return new CaptureValidation(CaptureRejection.Silent, "The applause capture is silent. Check the microphone and retake it.");
        }

        if (capture.MeanEnergy < baseline.MeanEnergy * MinimumEnergyRatioAboveBaseline)
        {
            return new CaptureValidation(
                CaptureRejection.InsufficientAboveBaseline,
                "The applause was not sufficiently above the shared ambient baseline. Retake it.");
        }

        return CaptureValidation.Accepted;
    }

    public NormalizedAudienceScore Normalize(
        CaptureStatistics performerA,
        CaptureStatistics performerB,
        CaptureStatistics baseline)
    {
        var adjustedA = AdjustedEnergy(performerA.MeanEnergy, baseline.MeanEnergy);
        var adjustedB = AdjustedEnergy(performerB.MeanEnergy, baseline.MeanEnergy);
        var combined = adjustedA + adjustedB;

        var scoreA = (int)Math.Round(
            adjustedA / combined * NormalizedTotal,
            MidpointRounding.AwayFromZero);
        scoreA = Math.Clamp(scoreA, 0, NormalizedTotal);

        return new NormalizedAudienceScore(
            scoreA,
            NormalizedTotal - scoreA,
            adjustedA,
            adjustedB);
    }

    public static double AdjustedEnergy(double meanEnergy, double baselineEnergy)
    {
        return Math.Max(meanEnergy - baselineEnergy, EnergyFloor);
    }
}
