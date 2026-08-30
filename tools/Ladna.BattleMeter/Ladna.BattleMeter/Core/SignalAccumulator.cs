using System.Buffers.Binary;

namespace Ladna.BattleMeter.Core;

public sealed class SignalAccumulator
{
    public const double DbfsFloor = -120;
    private const double ClipThreshold = 0.999;
    private const double SmoothingFactor = 0.28;

    private double _sumSquares;
    private double _peak;
    private double _smoothedRms;
    private long _sampleCount;
    private long _clippedSampleCount;

    public long SampleCount => _sampleCount;

    public LiveLevel AddFloat32LittleEndian(ReadOnlySpan<byte> bytes, long maximumSamples, long targetSamples)
    {
        var availableSamples = bytes.Length / sizeof(float);
        var acceptedSamples = (int)Math.Min(availableSamples, Math.Max(0, maximumSamples));
        var chunkEnergy = 0d;
        var chunkPeak = 0d;

        for (var index = 0; index < acceptedSamples; index++)
        {
            var bits = BinaryPrimitives.ReadInt32LittleEndian(bytes.Slice(index * sizeof(float), sizeof(float)));
            var sample = BitConverter.Int32BitsToSingle(bits);

            if (!float.IsFinite(sample))
            {
                continue;
            }

            var absolute = Math.Abs((double)sample);
            var square = sample * (double)sample;
            _sumSquares += square;
            chunkEnergy += square;
            _peak = Math.Max(_peak, absolute);
            chunkPeak = Math.Max(chunkPeak, absolute);

            if (absolute >= ClipThreshold)
            {
                _clippedSampleCount++;
            }

            _sampleCount++;
        }

        var chunkRms = acceptedSamples == 0 ? 0 : Math.Sqrt(chunkEnergy / acceptedSamples);
        _smoothedRms = _sampleCount == acceptedSamples
            ? chunkRms
            : (SmoothingFactor * chunkRms) + ((1 - SmoothingFactor) * _smoothedRms);

        return new LiveLevel(
            ToDbfs(_smoothedRms),
            ToDbfs(chunkPeak),
            targetSamples <= 0 ? 0 : Math.Clamp(_sampleCount / (double)targetSamples, 0, 1));
    }

    public CaptureStatistics Complete(int sampleRate)
    {
        var meanEnergy = _sampleCount == 0 ? 0 : _sumSquares / _sampleCount;
        var rootMeanSquare = Math.Sqrt(meanEnergy);

        return new CaptureStatistics(
            _sampleCount,
            meanEnergy,
            rootMeanSquare,
            _peak,
            ToDbfs(rootMeanSquare),
            ToDbfs(_peak),
            _clippedSampleCount,
            sampleRate <= 0 ? TimeSpan.Zero : TimeSpan.FromSeconds(_sampleCount / (double)sampleRate));
    }

    public static double ToDbfs(double amplitude)
    {
        return amplitude <= 0
            ? DbfsFloor
            : Math.Max(DbfsFloor, 20 * Math.Log10(amplitude));
    }
}
