using System.Windows;
using Ladna.BattleMeter.Core;
using Ladna.BattleMeter.Models;

namespace Ladna.BattleMeter;

public partial class AudienceWindow : Window
{
    private string _locale = "en";

    public AudienceWindow()
    {
        InitializeComponent();
    }

    public void SetLocale(string locale)
    {
        _locale = AppText.NormalizeLocale(locale);
        CloseAudienceButton.ToolTip = AppText.Get(_locale, "CloseAudience");
    }

    public void SetMatch(BattleMatch? match)
    {
        NameA.Text = match?.PerformerA?.Name ?? "A";
        NameB.Text = match?.PerformerB?.Name ?? "B";
        ClearMeasurements();
    }

    public void ClearMeasurements()
    {
        MeterA.Value = 0;
        MeterB.Value = 0;
        LevelA.Text = "−∞ dBFS";
        LevelB.Text = "−∞ dBFS";
        StatsA.Text = string.Empty;
        StatsB.Text = string.Empty;
        PanelA.Opacity = 1;
        PanelB.Opacity = 1;
        HideMessage();
    }

    public void ShowBaseline()
    {
        PanelA.Opacity = 0.72;
        PanelB.Opacity = 0.72;
        ShowMessage(AppText.Get(_locale, "Ambient"), string.Empty);
    }

    public void ShowCountdown(PerformerSide side, int seconds)
    {
        PanelA.Opacity = side == PerformerSide.A ? 1 : 0.34;
        PanelB.Opacity = side == PerformerSide.B ? 1 : 0.34;
        ShowMessage(AppText.Get(_locale, "Countdown"), seconds.ToString());
    }

    public void ShowRecording(PerformerSide side)
    {
        PanelA.Opacity = side == PerformerSide.A ? 1 : 0.34;
        PanelB.Opacity = side == PerformerSide.B ? 1 : 0.34;
        ShowMessage(AppText.Get(_locale, "Recording"), string.Empty);
    }

    public void SetLevel(PerformerSide side, LiveLevel level)
    {
        var meterValue = Math.Clamp((level.SmoothedDbfs + 60) / 60 * 100, 0, 100);
        var label = $"{level.SmoothedDbfs:F1} dBFS";

        if (side == PerformerSide.A)
        {
            MeterA.Value = meterValue;
            LevelA.Text = label;
        }
        else
        {
            MeterB.Value = meterValue;
            LevelB.Text = label;
        }
    }

    public void ShowWaiting(BattleMatch match, bool juryDecision)
    {
        PanelA.Opacity = 0.72;
        PanelB.Opacity = 0.72;
        StatsA.Text = FormatStats(match, PerformerSide.A);
        StatsB.Text = FormatStats(match, PerformerSide.B);
        ShowMessage(AppText.Get(_locale, juryDecision ? "WaitingJury" : "WaitingJudges"), string.Empty);
    }

    public void ShowResult(BattleMatch match)
    {
        PanelA.Opacity = match.Winner?.Id == match.PerformerA?.Id ? 1 : 0.38;
        PanelB.Opacity = match.Winner?.Id == match.PerformerB?.Id ? 1 : 0.38;

        StatsA.Text = FormatStats(match, PerformerSide.A);
        StatsB.Text = FormatStats(match, PerformerSide.B);
        ShowMessage(AppText.Get(_locale, "Winner"), match.Winner?.Name ?? string.Empty);
    }

    public void HideMessage()
    {
        CenterMessagePanel.Visibility = Visibility.Collapsed;
        CenterCaption.Text = string.Empty;
        CenterMessage.Text = string.Empty;
    }

    private void CloseAudienceButton_OnClick(object sender, RoutedEventArgs e)
    {
        Close();
    }

    private void AudienceWindow_OnPreviewKeyDown(object sender, System.Windows.Input.KeyEventArgs e)
    {
        if (e.Key != System.Windows.Input.Key.Escape)
        {
            return;
        }

        e.Handled = true;
        Close();
    }

    private void ShowMessage(string caption, string message)
    {
        CenterCaption.Text = caption;
        CenterMessage.Text = message;
        CenterMessagePanel.Visibility = Visibility.Visible;
    }

    private string FormatStats(BattleMatch match, PerformerSide side)
    {
        var jury = side == PerformerSide.A ? match.JudgeVotes.VotesA : match.JudgeVotes.VotesB;
        var audience = side == PerformerSide.A ? match.Audience?.PercentageA : match.Audience?.PercentageB;
        var combined = side == PerformerSide.A ? match.Combined?.PercentageA : match.Combined?.PercentageB;
        var audienceLabel = audience is null ? "—" : $"{audience:F1}%";
        var combinedLabel = combined is null ? "—" : $"{combined:F1}%";
        return $"{AppText.Get(_locale, "Jury")} {jury}/{match.JudgeVotes.Required}   ·   {AppText.Get(_locale, "Audience")} {audienceLabel}   ·   {AppText.Get(_locale, "Combined")} {combinedLabel}";
    }
}
