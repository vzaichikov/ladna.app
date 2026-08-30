using Ladna.BattleMeter.Core;

namespace Ladna.BattleMeter.Tests;

[TestClass]
public sealed class SignalAnalyzerTests
{
    private readonly SignalAnalyzer _analyzer = new();

    [TestMethod]
    public void EqualAdjustedEnergyNormalizesToEqualIntegerScores()
    {
        var baseline = Statistics(0.01);
        var captureA = Statistics(0.11);
        var captureB = Statistics(0.11);

        var score = _analyzer.Normalize(captureA, captureB, baseline);

        Assert.AreEqual(500_000, score.ScoreA);
        Assert.AreEqual(500_000, score.ScoreB);
    }

    [TestMethod]
    public void DifferentLevelsFavorTheHigherIntegratedEnergy()
    {
        var baseline = Statistics(0.01);
        var score = _analyzer.Normalize(Statistics(0.21), Statistics(0.06), baseline);

        Assert.AreEqual(800_000, score.ScoreA);
        Assert.AreEqual(200_000, score.ScoreB);
        Assert.AreEqual(SignalAnalyzer.NormalizedTotal, score.ScoreA + score.ScoreB);
    }

    [TestMethod]
    public void BaselineEnergyIsSubtractedBeforeNormalization()
    {
        var score = _analyzer.Normalize(Statistics(0.05), Statistics(0.03), Statistics(0.02));

        Assert.AreEqual(750_000, score.ScoreA);
        Assert.AreEqual(250_000, score.ScoreB);
    }

    [TestMethod]
    public void SilentCaptureIsRejected()
    {
        var validation = _analyzer.ValidateCrowd(Statistics(1e-10), Statistics(1e-9));

        Assert.AreEqual(CaptureRejection.Silent, validation.Rejection);
    }

    [TestMethod]
    public void ClippedCaptureIsRejected()
    {
        var clipped = Statistics(0.2) with { ClippedSampleCount = 1 };

        var validation = _analyzer.ValidateCrowd(clipped, Statistics(0.01));

        Assert.AreEqual(CaptureRejection.Clipped, validation.Rejection);
    }

    [TestMethod]
    public void CaptureInsufficientlyAboveBaselineIsRejected()
    {
        var validation = _analyzer.ValidateCrowd(Statistics(0.011), Statistics(0.01));

        Assert.AreEqual(CaptureRejection.InsufficientAboveBaseline, validation.Rejection);
    }

    [TestMethod]
    public void StreamingAccumulatorConsumesOnlyTheExactRequestedSampleCount()
    {
        var samples = Enumerable.Repeat(0.25f, 20).ToArray();
        var bytes = new byte[samples.Length * sizeof(float)];
        Buffer.BlockCopy(samples, 0, bytes, 0, bytes.Length);
        var accumulator = new SignalAccumulator();

        var level = accumulator.AddFloat32LittleEndian(bytes, 10, 10);
        var result = accumulator.Complete(10);

        Assert.AreEqual(10, result.SampleCount);
        Assert.AreEqual(TimeSpan.FromSeconds(1), result.Duration);
        Assert.AreEqual(0.0625, result.MeanEnergy, 1e-10);
        Assert.AreEqual(1, level.Progress, 1e-10);
    }

    [TestMethod]
    public void StreamingAccumulatorFlagsClipping()
    {
        var samples = new[] { 0.1f, 1f, -0.999f, 0.2f };
        var bytes = new byte[samples.Length * sizeof(float)];
        Buffer.BlockCopy(samples, 0, bytes, 0, bytes.Length);
        var accumulator = new SignalAccumulator();

        accumulator.AddFloat32LittleEndian(bytes, samples.Length, samples.Length);
        var result = accumulator.Complete(48_000);

        Assert.AreEqual(2, result.ClippedSampleCount);
    }

    private static CaptureStatistics Statistics(double meanEnergy)
    {
        var rms = Math.Sqrt(meanEnergy);
        return new CaptureStatistics(
            240_000,
            meanEnergy,
            rms,
            rms,
            SignalAccumulator.ToDbfs(rms),
            SignalAccumulator.ToDbfs(rms),
            0,
            TimeSpan.FromSeconds(5));
    }
}
