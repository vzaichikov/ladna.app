using Ladna.BattleMeter.Core;
using NAudio.CoreAudioApi;
using NAudio.Wave;

namespace Ladna.BattleMeter.Services;

public sealed record MicrophoneDevice(string Id, string Name)
{
    public override string ToString() => Name;
}

public sealed class AudioDeviceException : Exception
{
    public AudioDeviceException(string message, Exception? innerException = null)
        : base(message, innerException)
    {
    }
}

public interface IAudioCaptureService
{
    IReadOnlyList<MicrophoneDevice> GetActiveMicrophones();

    Task<CaptureStatistics> CaptureAsync(
        string deviceId,
        TimeSpan duration,
        IProgress<LiveLevel>? progress,
        CancellationToken cancellationToken);
}

public sealed class WasapiAudioCaptureService : IAudioCaptureService
{
    public const int SampleRate = 48_000;
    private static readonly WaveFormat CaptureFormat = WaveFormat.CreateIeeeFloatWaveFormat(SampleRate, 1);

    public IReadOnlyList<MicrophoneDevice> GetActiveMicrophones()
    {
        try
        {
            using var enumerator = new MMDeviceEnumerator();
            var microphones = new List<MicrophoneDevice>();

            foreach (var device in enumerator.EnumerateAudioEndPoints(DataFlow.Capture, DeviceState.Active))
            {
                using (device)
                {
                    microphones.Add(new MicrophoneDevice(device.ID, device.FriendlyName));
                }
            }

            return microphones
                .OrderBy(device => device.Name, StringComparer.CurrentCultureIgnoreCase)
                .ToArray();
        }
        catch (Exception exception)
        {
            throw new AudioDeviceException("Windows could not enumerate recording devices.", exception);
        }
    }

    public async Task<CaptureStatistics> CaptureAsync(
        string deviceId,
        TimeSpan duration,
        IProgress<LiveLevel>? progress,
        CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(deviceId))
        {
            throw new AudioDeviceException("Select a microphone first.");
        }

        var targetSamples = checked((long)Math.Round(duration.TotalSeconds * SampleRate));
        var accumulator = new SignalAccumulator();

        using var timeout = new CancellationTokenSource(duration + TimeSpan.FromSeconds(3));
        using var linked = CancellationTokenSource.CreateLinkedTokenSource(cancellationToken, timeout.Token);

        try
        {
            using var enumerator = new MMDeviceEnumerator();
            using var device = enumerator.GetDevice(deviceId);
            await using var recorder = new WasapiRecorderBuilder()
                .WithDevice(device)
                .WithSharedMode()
                .WithEventSync()
                .WithBufferLength(50)
                .WithFormat(CaptureFormat)
                .WithMmcssThreadPriority("Audio")
                .Build();

            await foreach (var buffer in recorder.CaptureAsync(linked.Token))
            {
                var remaining = targetSamples - accumulator.SampleCount;
                var level = accumulator.AddFloat32LittleEndian(buffer.Data.Span, remaining, targetSamples);
                progress?.Report(level);

                if (accumulator.SampleCount >= targetSamples)
                {
                    break;
                }
            }
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            throw;
        }
        catch (OperationCanceledException exception)
        {
            throw new AudioDeviceException("The microphone stopped before the capture was complete.", exception);
        }
        catch (Exception exception) when (exception is not AudioDeviceException)
        {
            throw new AudioDeviceException("The microphone disconnected or could not be opened.", exception);
        }

        if (accumulator.SampleCount != targetSamples)
        {
            throw new AudioDeviceException("The microphone disconnected before enough samples were captured.");
        }

        return accumulator.Complete(SampleRate);
    }
}
