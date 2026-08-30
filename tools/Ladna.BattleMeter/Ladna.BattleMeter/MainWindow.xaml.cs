using System.ComponentModel;
using System.Net;
using System.Windows;
using System.Windows.Controls;
using Ladna.BattleMeter.Core;
using Ladna.BattleMeter.Models;
using Ladna.BattleMeter.Services;
using MessageBox = System.Windows.MessageBox;

namespace Ladna.BattleMeter;

public partial class MainWindow : Window
{
    private static readonly TimeSpan BaselineDuration = TimeSpan.FromSeconds(2);
    private static readonly TimeSpan ApplauseDuration = TimeSpan.FromSeconds(5);
    private readonly SecureSettingsStore _settingsStore = new();
    private readonly WasapiAudioCaptureService _audioCapture = new();
    private readonly SignalAnalyzer _signalAnalyzer = new();
    private readonly LadnaApiClient _apiClient = new();
    private readonly OperatorSession _session = new();
    private readonly SubmissionCoordinator _submission;
    private AudienceWindow? _audienceWindow;
    private CancellationTokenSource? _operationCancellation;
    private AppSettings _settings = new();
    private string _locale = "en";
    private int _pollIntervalSeconds = 5;
    private bool _busy;
    private bool _suppressSelectionEvents;

    public MainWindow()
    {
        InitializeComponent();
        _submission = new SubmissionCoordinator(_apiClient);
        Loaded += MainWindow_OnLoaded;
        Closing += MainWindow_OnClosing;
    }

    private void MainWindow_OnLoaded(object sender, RoutedEventArgs e)
    {
        var loaded = _settingsStore.Load();
        _settings = loaded.Settings;
        ServerTextBox.Text = _settings.ServerUrl;
        TokenPasswordBox.Password = loaded.Token;
        _locale = AppText.NormalizeLocale(string.IsNullOrWhiteSpace(_settings.Locale) ? AppText.SystemLocale : _settings.Locale);
        SelectLocale(_locale);
        RefreshHardware(_settings.MicrophoneId, _settings.AudienceDisplayId);
        ApplyLocale();
        UpdateControls();
    }

    private void MainWindow_OnClosing(object? sender, CancelEventArgs e)
    {
        CancelCurrentOperation();
        _audienceWindow?.Close();
        _apiClient.Dispose();
    }

    private async void ConnectButton_OnClick(object sender, RoutedEventArgs e)
    {
        await RunUiOperationAsync(async cancellationToken =>
        {
            var server = CurrentServer();
            var token = CurrentToken();
            SetStatus(_locale == "uk" ? "Підключення до Ladna…" : "Connecting to Ladna…");
            var response = await _apiClient.GetMatchesAsync(server, token, cancellationToken);

            _pollIntervalSeconds = Math.Clamp(response.Meta.PollIntervalSeconds, 1, 60);
            if (!string.IsNullOrWhiteSpace(response.Meta.Locale))
            {
                _locale = AppText.NormalizeLocale(response.Meta.Locale);
                SelectLocale(_locale);
                ApplyLocale();
            }

            _suppressSelectionEvents = true;
            MatchCombo.ItemsSource = response.Data;
            MatchCombo.SelectedIndex = -1;
            _suppressSelectionEvents = false;
            ResetMatch();

            SetStatus(response.Data.Count == 0
                ? (_locale == "uk" ? "Немає готових батлів у опублікованих або активних фестивалях." : "No ready matches were found in published or in-progress editions.")
                : (_locale == "uk" ? $"Завантажено батлів: {response.Data.Count}. Оберіть батл." : $"Loaded {response.Data.Count} ready match(es). Select one explicitly."));
        });
    }

    private void SaveButton_OnClick(object sender, RoutedEventArgs e)
    {
        try
        {
            _settings = CurrentSettings();
            _settingsStore.Save(_settings, CurrentToken());
            SetStatus(_locale == "uk"
                ? "Налаштування збережено. Токен захищено Windows DPAPI для поточного користувача."
                : "Settings saved. The token is protected by Windows DPAPI for the current user.");
        }
        catch (Exception exception)
        {
            ShowError(exception.Message);
        }
    }

    private void RefreshDevicesButton_OnClick(object sender, RoutedEventArgs e)
    {
        RefreshHardware(
            (MicrophoneCombo.SelectedItem as MicrophoneDevice)?.Id,
            (DisplayCombo.SelectedItem as AudienceDisplay)?.Id);
    }

    private async void TestMicrophoneButton_OnClick(object sender, RoutedEventArgs e)
    {
        await RunUiOperationAsync(async cancellationToken =>
        {
            var microphone = SelectedMicrophone();
            SetStatus(_locale == "uk" ? "Перевірка мікрофона 2 секунди…" : "Testing microphone for 2 seconds…");
            var progress = CreateLevelProgress(null);
            var test = await _audioCapture.CaptureAsync(microphone.Id, BaselineDuration, progress, cancellationToken);
            var validation = _signalAnalyzer.ValidateBaseline(test);

            if (!validation.IsAccepted)
            {
                throw new AudioDeviceException(validation.Message);
            }

            var warning = test.PeakDbfs > -3
                ? (_locale == "uk" ? " Рівень близький до кліпінгу — зменште підсилення." : " The level is close to clipping; reduce gain.")
                : string.Empty;
            SetStatus($"{(_locale == "uk" ? "Мікрофон працює" : "Microphone is live")}: RMS {test.RelativeDbfs:F1} dBFS, peak {test.PeakDbfs:F1} dBFS.{warning}");
        });
    }

    private void ShowAudienceButton_OnClick(object sender, RoutedEventArgs e)
    {
        try
        {
            ShowAudienceDisplay();
            SetStatus(_locale == "uk" ? "Екран глядачів відкрито." : "Audience display opened.");
        }
        catch (Exception exception)
        {
            ShowError(exception.Message);
        }
    }

    private void CloseAudienceButton_OnClick(object sender, RoutedEventArgs e)
    {
        if (_audienceWindow is null)
        {
            return;
        }

        _audienceWindow.Close();
    }

    private async void BaselineButton_OnClick(object sender, RoutedEventArgs e)
    {
        await RunUiOperationAsync(async cancellationToken =>
        {
            EnsureMatchSelected();
            var microphone = SelectedMicrophone();

            if (_session.Baseline is not null && !ConfirmRetake())
            {
                return;
            }

            _submission.Clear();
            _session.BeginBaseline();
            _audienceWindow?.ShowBaseline();
            SetStatus(_locale == "uk" ? "Записуємо спільний фоновий рівень рівно 2 секунди…" : "Capturing the shared ambient baseline for exactly 2 seconds…");

            try
            {
                var baseline = await _audioCapture.CaptureAsync(
                    microphone.Id,
                    BaselineDuration,
                    CreateLevelProgress(null),
                    cancellationToken);
                var validation = _signalAnalyzer.ValidateBaseline(baseline);

                if (!validation.IsAccepted)
                {
                    throw new AudioDeviceException(validation.Message);
                }

                _session.CompleteBaseline(baseline);
                _audienceWindow?.HideMessage();
                SetStatus($"{(_locale == "uk" ? "Фон прийнято" : "Baseline accepted")}: {baseline.RelativeDbfs:F1} dBFS.");
                UpdateMeasurementSummary();
            }
            catch
            {
                _session.CaptureFailed();
                _audienceWindow?.HideMessage();
                throw;
            }
        });
    }

    private async void CaptureAButton_OnClick(object sender, RoutedEventArgs e)
    {
        await CapturePerformerAsync(PerformerSide.A);
    }

    private async void CaptureBButton_OnClick(object sender, RoutedEventArgs e)
    {
        await CapturePerformerAsync(PerformerSide.B);
    }

    private void RetakeAButton_OnClick(object sender, RoutedEventArgs e)
    {
        ConfirmAndClearCapture(PerformerSide.A);
    }

    private void RetakeBButton_OnClick(object sender, RoutedEventArgs e)
    {
        ConfirmAndClearCapture(PerformerSide.B);
    }

    private async void SubmitButton_OnClick(object sender, RoutedEventArgs e)
    {
        await RunUiOperationAsync(SubmitAndPollAsync);
    }

    private void StopWaitingButton_OnClick(object sender, RoutedEventArgs e)
    {
        CancelCurrentOperation();
    }

    private void MatchCombo_OnSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (_suppressSelectionEvents)
        {
            return;
        }

        CancelCurrentOperation();
        _submission.Clear();

        if (MatchCombo.SelectedItem is not BattleMatch match)
        {
            ResetMatch();
            return;
        }

        _session.SelectMatch(match.Id);
        SelectedMatchTitle.Text = $"{match.EditionLabel} · {match.CategoryLabel} · R{match.RoundNumber} / #{match.Position}";
        SelectedPerformers.Text = $"{match.PerformerA?.Name}  vs  {match.PerformerB?.Name}";
        ResultPanel.Visibility = Visibility.Collapsed;
        _audienceWindow?.SetMatch(match);
        CaptureProgress.Value = 0;
        LiveLevelText.Text = "−∞ dBFS";
        UpdateMeasurementSummary();
        SetStatus(_locale == "uk" ? "Батл вибрано. Запишіть спільний фоновий рівень." : "Match selected. Capture the shared ambient baseline.");
        UpdateControls();
    }

    private void MicrophoneCombo_OnSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (_suppressSelectionEvents)
        {
            return;
        }

        CancelCurrentOperation();
        _submission.Clear();
        _session.ChangeMicrophone();
        _audienceWindow?.ClearMeasurements();
        UpdateMeasurementSummary();
        SetStatus(_locale == "uk"
            ? "Мікрофон змінено: фоновий рівень і обидва записи очищено."
            : "Microphone changed: the baseline and both performer captures were cleared.");
        UpdateControls();
    }

    private void DisplayCombo_OnSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (_suppressSelectionEvents || _audienceWindow is not { IsVisible: true })
        {
            return;
        }

        if (DisplayCombo.SelectedItem is AudienceDisplay display)
        {
            AudienceDisplayService.PlaceFullscreen(_audienceWindow, display);
        }
    }

    private void LocaleCombo_OnSelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (LocaleCombo.SelectedItem is not ComboBoxItem item || item.Tag is not string locale)
        {
            return;
        }

        _locale = AppText.NormalizeLocale(locale);
        ApplyLocale();
    }

    private async Task CapturePerformerAsync(PerformerSide side)
    {
        await RunUiOperationAsync(async cancellationToken =>
        {
            var match = EnsureMatchSelected();
            var microphone = SelectedMicrophone();

            if (_session.Baseline is null)
            {
                throw new InvalidOperationException(_locale == "uk" ? "Спочатку запишіть фоновий рівень." : "Capture the shared baseline first.");
            }

            var existing = side == PerformerSide.A ? _session.CaptureA : _session.CaptureB;
            if (existing is not null)
            {
                if (!ConfirmRetake())
                {
                    return;
                }

                _session.Retake(side);
            }

            _session.BeginCountdown(side);
            var performerName = side == PerformerSide.A ? match.PerformerA?.Name : match.PerformerB?.Name;

            for (var seconds = 3; seconds >= 1; seconds--)
            {
                _audienceWindow?.ShowCountdown(side, seconds);
                SetStatus($"{performerName}: {seconds}…");
                await Task.Delay(TimeSpan.FromSeconds(1), cancellationToken);
            }

            _session.BeginPerformerCapture();
            _audienceWindow?.ShowRecording(side);
            SetStatus($"{performerName}: {AppText.Get(_locale, "Recording")}");

            try
            {
                var capture = await _audioCapture.CaptureAsync(
                    microphone.Id,
                    ApplauseDuration,
                    CreateLevelProgress(side),
                    cancellationToken);
                var validation = _signalAnalyzer.ValidateCrowd(capture, _session.Baseline);

                if (!validation.IsAccepted)
                {
                    throw new AudioDeviceException(validation.Message);
                }

                _session.CompletePerformerCapture(capture);
                _audienceWindow?.HideMessage();
                SetStatus($"{performerName}: {(_locale == "uk" ? "запис прийнято" : "capture accepted")} · {capture.RelativeDbfs:F1} dBFS.");
                UpdateMeasurementSummary();
            }
            catch
            {
                _session.CaptureFailed();
                _audienceWindow?.HideMessage();
                throw;
            }
        });
    }

    private async Task SubmitAndPollAsync(CancellationToken cancellationToken)
    {
        var match = EnsureMatchSelected();

        if (!_submission.HasPendingSubmission)
        {
            if (_session.Baseline is null || _session.CaptureA is null || _session.CaptureB is null)
            {
                throw new InvalidOperationException(_locale == "uk" ? "Потрібні два прийняті записи." : "Two accepted performer captures are required.");
            }

            var normalized = _signalAnalyzer.Normalize(_session.CaptureA, _session.CaptureB, _session.Baseline);
            var submission = new AudienceScoreSubmission
            {
                AudienceScoreA = normalized.ScoreA,
                AudienceScoreB = normalized.ScoreB,
                Measurement = new MeasurementMetadata
                {
                    BaselineDbfs = _session.Baseline.RelativeDbfs,
                    MeanDbfsA = _session.CaptureA.RelativeDbfs,
                    MeanDbfsB = _session.CaptureB.RelativeDbfs,
                    PeakDbfsA = _session.CaptureA.PeakDbfs,
                    PeakDbfsB = _session.CaptureB.PeakDbfs,
                },
            };

            _submission.Prepare(CurrentServer(), CurrentToken(), match.Id, submission);
            _session.BeginSubmission();
            UpdateControls();
        }
        else
        {
            _submission.Prepare(CurrentServer(), CurrentToken(), match.Id, _submission.PendingSubmission!);
        }

        while (_submission.HasPendingSubmission)
        {
            SetStatus(_locale == "uk" ? "Надсилання голосу глядачів…" : "Submitting the audience vote…");
            var outcome = await _submission.SubmitOrPollAsync(cancellationToken);
            _session.ApplySubmissionState(outcome.State);

            if (outcome.State == SubmissionState.Completed && outcome.Match is not null)
            {
                ShowOfficialResult(outcome.Match);
                return;
            }

            if (outcome.ShouldPoll)
            {
                var juryDecision = outcome.State == SubmissionState.WaitingForJuryDecision;
                if (outcome.Match is not null)
                {
                    _audienceWindow?.ShowWaiting(outcome.Match, juryDecision);
                    ResultPanel.Visibility = Visibility.Visible;
                    ResultText.Text = FormatPendingResult(outcome.Match, juryDecision);
                }
                SetStatus(AppText.Get(_locale, juryDecision ? "WaitingJury" : "WaitingJudges"));
                await Task.Delay(TimeSpan.FromSeconds(_pollIntervalSeconds), cancellationToken);
                continue;
            }

            SetStatus(FormatSubmissionError(outcome));
            ResultPanel.Visibility = Visibility.Visible;
            ResultText.Text = FormatSubmissionError(outcome);
            return;
        }
    }

    private void ConfirmAndClearCapture(PerformerSide side)
    {
        var capture = side == PerformerSide.A ? _session.CaptureA : _session.CaptureB;
        if (capture is null || !ConfirmRetake())
        {
            return;
        }

        CancelCurrentOperation();
        _submission.Clear();
        _session.Retake(side);
        _audienceWindow?.ClearMeasurements();
        UpdateMeasurementSummary();
        SetStatus(_locale == "uk" ? "Запис очищено. Запустіть перезапис." : "Capture cleared. Start the retake when ready.");
        UpdateControls();
    }

    private bool ConfirmRetake()
    {
        return MessageBox.Show(
            AppText.Get(_locale, "ConfirmRetake"),
            AppText.Get(_locale, "AppTitle"),
            MessageBoxButton.YesNo,
            MessageBoxImage.Question,
            MessageBoxResult.No) == MessageBoxResult.Yes;
    }

    private void ShowOfficialResult(BattleMatch match)
    {
        var winner = match.Winner?.Name ?? (_locale == "uk" ? "Очікується офіційний результат" : "Official result pending");
        var a = match.PerformerA?.Name ?? "A";
        var b = match.PerformerB?.Name ?? "B";
        var details = $"{a}: {AppText.Get(_locale, "Jury")} {match.JudgeVotes.VotesA}/{match.JudgeVotes.Required}, {AppText.Get(_locale, "Audience")} {match.Audience?.PercentageA ?? 0:F1}%, {AppText.Get(_locale, "Combined")} {match.Combined?.PercentageA ?? 0:F1}%\n" +
                      $"{b}: {AppText.Get(_locale, "Jury")} {match.JudgeVotes.VotesB}/{match.JudgeVotes.Required}, {AppText.Get(_locale, "Audience")} {match.Audience?.PercentageB ?? 0:F1}%, {AppText.Get(_locale, "Combined")} {match.Combined?.PercentageB ?? 0:F1}%";

        ResultPanel.Visibility = Visibility.Visible;
        ResultText.Text = $"{AppText.Get(_locale, "Winner")}: {winner}\n{details}";
        SetStatus($"{AppText.Get(_locale, "Winner")}: {winner}");
        _audienceWindow?.ShowResult(match);
        UpdateControls();
    }

    private string FormatPendingResult(BattleMatch match, bool juryDecision)
    {
        var state = AppText.Get(_locale, juryDecision ? "WaitingJury" : "WaitingJudges");
        var audienceA = match.Audience?.PercentageA is double percentageA ? $"{percentageA:F1}%" : "—";
        var audienceB = match.Audience?.PercentageB is double percentageB ? $"{percentageB:F1}%" : "—";
        return $"{state}\n{AppText.Get(_locale, "Jury")}: A {match.JudgeVotes.VotesA} · B {match.JudgeVotes.VotesB} · {match.JudgeVotes.Submitted}/{match.JudgeVotes.Required}\n{AppText.Get(_locale, "Audience")}: A {audienceA} · B {audienceB}\n{AppText.Get(_locale, "Combined")}: —";
    }

    private void RefreshHardware(string? preferredMicrophoneId, string? preferredDisplayId)
    {
        try
        {
            _suppressSelectionEvents = true;
            var microphones = _audioCapture.GetActiveMicrophones();
            MicrophoneCombo.ItemsSource = microphones;
            MicrophoneCombo.SelectedItem = microphones.FirstOrDefault(item => item.Id == preferredMicrophoneId);
            if (MicrophoneCombo.SelectedItem is null && microphones.Count > 0)
            {
                MicrophoneCombo.SelectedIndex = 0;
            }
            var selectedMicrophoneId = (MicrophoneCombo.SelectedItem as MicrophoneDevice)?.Id;

            var displays = AudienceDisplayService.GetDisplays();
            DisplayCombo.ItemsSource = displays;
            DisplayCombo.SelectedItem = displays.FirstOrDefault(item => item.Id == preferredDisplayId)
                ?? displays.FirstOrDefault(item => !item.IsPrimary)
                ?? displays.FirstOrDefault();
            _suppressSelectionEvents = false;

            if (_session.MatchId is not null
                && preferredMicrophoneId is not null
                && !string.Equals(preferredMicrophoneId, selectedMicrophoneId, StringComparison.Ordinal))
            {
                _submission.Clear();
                _session.ChangeMicrophone();
                _audienceWindow?.ClearMeasurements();
                UpdateMeasurementSummary();
                SetStatus(_locale == "uk"
                    ? "Вибраний мікрофон більше недоступний: фон і обидва записи очищено."
                    : "The selected microphone is no longer available: the baseline and both captures were cleared.");
            }
            else
            {
                SetStatus(microphones.Count == 0
                    ? (_locale == "uk" ? "Активних мікрофонів не знайдено." : "No active microphones were found.")
                    : (_locale == "uk" ? "Пристрої оновлено." : "Hardware list refreshed."));
            }
        }
        catch (Exception exception)
        {
            _suppressSelectionEvents = false;
            ShowError(exception.Message);
        }

        UpdateControls();
    }

    private void ShowAudienceDisplay()
    {
        var display = DisplayCombo.SelectedItem as AudienceDisplay
            ?? throw new InvalidOperationException(_locale == "uk" ? "Оберіть екран глядачів." : "Select an audience display.");

        if (_audienceWindow is null)
        {
            _audienceWindow = new AudienceWindow();
            _audienceWindow.Closed += (_, _) =>
            {
                _audienceWindow = null;
                SetStatus(_locale == "uk" ? "Екран глядачів закрито." : "Audience display closed.");
                UpdateControls();
            };
        }

        _audienceWindow.SetLocale(_locale);
        _audienceWindow.SetMatch(MatchCombo.SelectedItem as BattleMatch);
        AudienceDisplayService.PlaceFullscreen(_audienceWindow, display);
    }

    private Progress<LiveLevel> CreateLevelProgress(PerformerSide? side)
    {
        return new Progress<LiveLevel>(level =>
        {
            CaptureProgress.Value = level.Progress * 100;
            LiveLevelText.Text = $"{level.SmoothedDbfs:F1} dBFS";

            if (side is PerformerSide performerSide)
            {
                _audienceWindow?.SetLevel(performerSide, level);
            }
            else
            {
                _audienceWindow?.SetLevel(PerformerSide.A, level);
                _audienceWindow?.SetLevel(PerformerSide.B, level);
            }
        });
    }

    private void UpdateMeasurementSummary()
    {
        var baseline = _session.Baseline is null ? "—" : $"{_session.Baseline.RelativeDbfs:F1} dBFS";
        var captureA = _session.CaptureA is null ? "—" : $"{_session.CaptureA.RelativeDbfs:F1} dBFS";
        var captureB = _session.CaptureB is null ? "—" : $"{_session.CaptureB.RelativeDbfs:F1} dBFS";

        MeasurementSummary.Text = _locale == "uk"
            ? $"Фон: {baseline}\nУчасник A: {captureA}\nУчасник B: {captureB}"
            : $"Baseline: {baseline}\nPerformer A: {captureA}\nPerformer B: {captureB}";

        if (_session.Baseline is not null && _session.CaptureA is not null && _session.CaptureB is not null)
        {
            var score = _signalAnalyzer.Normalize(_session.CaptureA, _session.CaptureB, _session.Baseline);
            ScoreSummary.Text = $"A {score.ScoreA / 10_000d:F1}%  ·  B {score.ScoreB / 10_000d:F1}%\n{score.ScoreA:N0} + {score.ScoreB:N0} = 1,000,000";
        }
        else
        {
            ScoreSummary.Text = string.Empty;
        }

        UpdateControls();
    }

    private void ResetMatch()
    {
        CancelCurrentOperation();
        _submission.Clear();
        _session.ClearMatch();
        SelectedMatchTitle.Text = _locale == "uk" ? "Батл не вибрано" : "No match selected";
        SelectedPerformers.Text = string.Empty;
        ResultPanel.Visibility = Visibility.Collapsed;
        _audienceWindow?.SetMatch(null);
        UpdateMeasurementSummary();
    }

    private BattleMatch EnsureMatchSelected()
    {
        return MatchCombo.SelectedItem as BattleMatch
            ?? throw new InvalidOperationException(_locale == "uk" ? "Оберіть конкретний готовий батл." : "Select a specific ready match first.");
    }

    private MicrophoneDevice SelectedMicrophone()
    {
        return MicrophoneCombo.SelectedItem as MicrophoneDevice
            ?? throw new AudioDeviceException(_locale == "uk" ? "Оберіть мікрофон." : "Select a microphone first.");
    }

    private Uri CurrentServer() => LadnaApiClient.ValidateServer(ServerTextBox.Text);

    private string CurrentToken()
    {
        var token = TokenPasswordBox.Password.Trim();
        return string.IsNullOrWhiteSpace(token)
            ? throw new ArgumentException(_locale == "uk" ? "Введіть API-токен Ladna." : "Enter a Ladna API token.")
            : token;
    }

    private AppSettings CurrentSettings()
    {
        return new AppSettings
        {
            ServerUrl = CurrentServer().GetLeftPart(UriPartial.Authority).TrimEnd('/'),
            MicrophoneId = (MicrophoneCombo.SelectedItem as MicrophoneDevice)?.Id,
            AudienceDisplayId = (DisplayCombo.SelectedItem as AudienceDisplay)?.Id,
            Locale = _locale,
        };
    }

    private async Task RunUiOperationAsync(
        Func<CancellationToken, Task> operation)
    {
        if (_busy)
        {
            return;
        }

        _busy = true;
        CancelCurrentOperation();
        _operationCancellation = new CancellationTokenSource();
        UpdateControls();

        try
        {
            await operation(_operationCancellation.Token);
        }
        catch (OperationCanceledException)
        {
            SetStatus(_locale == "uk" ? "Операцію скасовано." : "Operation cancelled.");
        }
        catch (BattleApiException exception)
        {
            ShowError(FormatApiError(exception));
        }
        catch (Exception exception)
        {
            ShowError(exception.Message);
        }
        finally
        {
            _busy = false;
            _operationCancellation?.Dispose();
            _operationCancellation = null;
            UpdateControls();
        }
    }

    private void CancelCurrentOperation()
    {
        _operationCancellation?.Cancel();
    }

    private void UpdateControls()
    {
        var hasMatch = MatchCombo.SelectedItem is BattleMatch;
        var hasMicrophone = MicrophoneCombo.SelectedItem is MicrophoneDevice;
        var canMeasure = hasMatch && hasMicrophone && !_busy && _session.State is not OperatorState.Completed;

        ConnectButton.IsEnabled = !_busy;
        SaveButton.IsEnabled = !_busy;
        ServerTextBox.IsEnabled = !_busy;
        TokenPasswordBox.IsEnabled = !_busy;
        RefreshDevicesButton.IsEnabled = !_busy;
        TestMicrophoneButton.IsEnabled = hasMicrophone && !_busy;
        ShowAudienceButton.IsEnabled = DisplayCombo.SelectedItem is AudienceDisplay && !_busy;
        CloseAudienceButton.IsEnabled = _audienceWindow is { IsVisible: true } && !_busy;
        MatchCombo.IsEnabled = !_busy;
        MicrophoneCombo.IsEnabled = !_busy;
        DisplayCombo.IsEnabled = !_busy;
        BaselineButton.IsEnabled = canMeasure;
        CaptureAButton.IsEnabled = canMeasure && _session.Baseline is not null;
        CaptureBButton.IsEnabled = canMeasure && _session.Baseline is not null;
        RetakeAButton.IsEnabled = !_busy && _session.CaptureA is not null && !_submission.HasPendingSubmission;
        RetakeBButton.IsEnabled = !_busy && _session.CaptureB is not null && !_submission.HasPendingSubmission;
        SubmitButton.IsEnabled = !_busy && (_submission.HasPendingSubmission || (_session.CaptureA is not null && _session.CaptureB is not null));
        SubmitButton.Content = _submission.HasPendingSubmission ? AppText.Get(_locale, "Retry") : AppText.Get(_locale, "Submit");
        StopWaitingButton.Visibility = _busy && _submission.HasPendingSubmission ? Visibility.Visible : Visibility.Collapsed;
        StopWaitingButton.IsEnabled = _busy && _submission.HasPendingSubmission;
    }

    private void ApplyLocale()
    {
        var uk = _locale == "uk";
        Title = AppText.Get(_locale, "AppTitle");
        TitleText.Text = AppText.Get(_locale, "AppTitle");
        SubtitleText.Text = uk ? "Відносний голос енергії натовпу · аудіо не зберігається й не передається" : "Relative crowd-energy vote · no audio is stored or uploaded";
        ConnectionGroup.Header = uk ? "Підключення Ladna" : "Ladna connection";
        HardwareGroup.Header = uk ? "Обладнання" : "Hardware";
        MatchGroup.Header = uk ? "Батл" : "Battle match";
        MeasurementGroup.Header = uk ? "Вимірювання" : "Measurement";
        ServerLabel.Text = uk ? "Сервер" : "Server";
        TokenLabel.Text = uk ? "API-токен (захищений Windows DPAPI для цього користувача)" : "API token (protected for this Windows user with DPAPI)";
        MicrophoneLabel.Text = uk ? "Мікрофон" : "Microphone";
        DisplayLabel.Text = uk ? "Екран глядачів" : "Audience display";
        MatchLabel.Text = uk ? "Оберіть конкретний готовий батл" : "Select a ready match explicitly";
        ConnectButton.Content = AppText.Get(_locale, "Connect");
        SaveButton.Content = AppText.Get(_locale, "Save");
        RefreshDevicesButton.Content = AppText.Get(_locale, "RefreshDevices");
        TestMicrophoneButton.Content = AppText.Get(_locale, "TestMicrophone");
        ShowAudienceButton.Content = AppText.Get(_locale, "ShowAudience");
        CloseAudienceButton.Content = AppText.Get(_locale, "CloseAudience");
        BaselineButton.Content = AppText.Get(_locale, "CaptureBaseline");
        CaptureAButton.Content = AppText.Get(_locale, "CaptureA");
        CaptureBButton.Content = AppText.Get(_locale, "CaptureB");
        RetakeAButton.Content = AppText.Get(_locale, "RetakeA");
        RetakeBButton.Content = AppText.Get(_locale, "RetakeB");
        StopWaitingButton.Content = AppText.Get(_locale, "StopWaiting");
        _audienceWindow?.SetLocale(_locale);
        UpdateMeasurementSummary();
    }

    private void SelectLocale(string locale)
    {
        _suppressSelectionEvents = true;
        LocaleCombo.SelectedItem = LocaleCombo.Items
            .OfType<ComboBoxItem>()
            .FirstOrDefault(item => string.Equals(item.Tag as string, locale, StringComparison.OrdinalIgnoreCase));
        _suppressSelectionEvents = false;
    }

    private void SetStatus(string message)
    {
        StatusText.Text = message;
    }

    private void ShowError(string message)
    {
        SetStatus(message);
        MessageBox.Show(message, AppText.Get(_locale, "AppTitle"), MessageBoxButton.OK, MessageBoxImage.Warning);
    }

    private string FormatApiError(BattleApiException exception)
    {
        var prefix = exception.StatusCode switch
        {
            HttpStatusCode.Unauthorized => _locale == "uk" ? "Токен відсутній, недійсний або відкликаний." : "The token is missing, invalid, or revoked.",
            HttpStatusCode.PaymentRequired => _locale == "uk" ? "Для акаунта потрібна активна підписка Ladna." : "The account needs an active Ladna subscription.",
            HttpStatusCode.Forbidden => _locale == "uk" ? "Токен не має права керувати батлами." : "The token cannot operate Festival battles.",
            HttpStatusCode.NotFound => _locale == "uk" ? "Батл не знайдено для цього акаунта." : "The match is unavailable to this account.",
            HttpStatusCode.Conflict => _locale == "uk" ? "Збережений результат конфліктує з цим повтором." : "The stored result conflicts with this retry.",
            HttpStatusCode.UnprocessableEntity => _locale == "uk" ? "Ladna відхилила дані вимірювання." : "Ladna rejected the measurement data.",
            HttpStatusCode.Locked => _locale == "uk" ? "Фестиваль зараз доступний лише для читання." : "The Festival is currently read-only.",
            HttpStatusCode.TooManyRequests => _locale == "uk" ? "Забагато запитів; зачекайте й повторіть." : "Too many requests; wait and retry.",
            _ => _locale == "uk" ? "Помилка API Ladna." : "Ladna API error.",
        };

        return $"{prefix} {exception.Message}";
    }

    private string FormatSubmissionError(SubmissionOutcome outcome)
    {
        var guidance = outcome.State switch
        {
            SubmissionState.AuthenticationRequired => _locale == "uk" ? "Перевірте або замініть токен." : "Check or replace the API token.",
            SubmissionState.SubscriptionRequired => _locale == "uk" ? "Власник акаунта має поновити підписку Ladna." : "The account owner must renew the Ladna subscription.",
            SubmissionState.Forbidden => _locale == "uk" ? "Токену потрібен доступ festival_battles:operate." : "The token needs festival_battles:operate.",
            SubmissionState.MatchUnavailable => _locale == "uk" ? "Оновіть список і виберіть батл знову." : "Refresh the list and select the match again.",
            SubmissionState.Conflict => _locale == "uk" ? "Оновіть офіційний стан у Ladna; не перезаписуйте його." : "Refresh the official Ladna state; do not overwrite it.",
            SubmissionState.InvalidScore => _locale == "uk" ? "Два бали мають сумуватися рівно до 1 000 000." : "The two scores must total exactly 1,000,000.",
            SubmissionState.Locked => _locale == "uk" ? "Зверніться до менеджера або власника акаунта." : "Ask an account owner or Festival manager to unlock it.",
            SubmissionState.RateLimited => _locale == "uk" ? "Бал збережений у пам’яті. Повторіть трохи пізніше." : "The score remains in memory. Retry shortly.",
            SubmissionState.OfflineRetry => _locale == "uk" ? "Бал збережений у пам’яті. Відновіть зв’язок і повторіть." : "The score remains in memory. Restore connectivity and retry.",
            _ => _locale == "uk" ? "Бал збережений у пам’яті для безпечного повтору." : "The score remains in memory for a safe retry.",
        };

        return $"{outcome.Message} {guidance}";
    }
}
