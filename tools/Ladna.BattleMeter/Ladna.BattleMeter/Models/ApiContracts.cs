using System.Text.Json.Serialization;

namespace Ladna.BattleMeter.Models;

public sealed class BattleListResponse
{
    [JsonPropertyName("data")]
    public IReadOnlyList<BattleMatch> Data { get; init; } = [];

    [JsonPropertyName("meta")]
    public BattleListMeta Meta { get; init; } = new();
}

public sealed class BattleListMeta
{
    [JsonPropertyName("locale")]
    public string? Locale { get; init; }

    [JsonPropertyName("poll_interval_seconds")]
    public int PollIntervalSeconds { get; init; } = 5;
}

public sealed class BattleResponse
{
    [JsonPropertyName("data")]
    public BattleMatch Data { get; init; } = new();
}

public sealed class BattleMatch
{
    [JsonPropertyName("id")]
    public long Id { get; init; }

    [JsonPropertyName("edition_label")]
    public string EditionLabel { get; init; } = string.Empty;

    [JsonPropertyName("category_label")]
    public string CategoryLabel { get; init; } = string.Empty;

    [JsonPropertyName("round_number")]
    public int RoundNumber { get; init; }

    [JsonPropertyName("position")]
    public int Position { get; init; }

    [JsonPropertyName("state")]
    public string State { get; init; } = "ready";

    [JsonPropertyName("performer_a")]
    public BattlePerformer? PerformerA { get; init; }

    [JsonPropertyName("performer_b")]
    public BattlePerformer? PerformerB { get; init; }

    [JsonPropertyName("judge_votes")]
    public JudgeVoteAggregate JudgeVotes { get; init; } = new();

    [JsonPropertyName("audience")]
    public AudienceAggregate? Audience { get; init; }

    [JsonPropertyName("combined")]
    public CombinedAggregate? Combined { get; init; }

    [JsonPropertyName("winner")]
    public BattlePerformer? Winner { get; init; }

    [JsonIgnore]
    public string DisplayLabel => $"{EditionLabel} · {CategoryLabel} · R{RoundNumber} / #{Position} · {PerformerA?.Name} vs {PerformerB?.Name}";
}

public sealed class BattlePerformer
{
    [JsonPropertyName("id")]
    public long Id { get; init; }

    [JsonPropertyName("name")]
    public string Name { get; init; } = string.Empty;
}

public sealed class JudgeVoteAggregate
{
    [JsonPropertyName("required")]
    public int Required { get; init; } = 4;

    [JsonPropertyName("submitted")]
    public int Submitted { get; init; }

    [JsonPropertyName("a")]
    public int VotesA { get; init; }

    [JsonPropertyName("b")]
    public int VotesB { get; init; }
}

public sealed class AudienceAggregate
{
    [JsonPropertyName("score_a")]
    public int? ScoreA { get; init; }

    [JsonPropertyName("score_b")]
    public int? ScoreB { get; init; }

    [JsonPropertyName("percentage_a")]
    public double? PercentageA { get; init; }

    [JsonPropertyName("percentage_b")]
    public double? PercentageB { get; init; }
}

public sealed class CombinedAggregate
{
    [JsonPropertyName("percentage_a")]
    public double? PercentageA { get; init; }

    [JsonPropertyName("percentage_b")]
    public double? PercentageB { get; init; }
}

public sealed class AudienceScoreSubmission
{
    [JsonPropertyName("audience_score_a")]
    public int AudienceScoreA { get; init; }

    [JsonPropertyName("audience_score_b")]
    public int AudienceScoreB { get; init; }

    [JsonPropertyName("measurement")]
    public MeasurementMetadata Measurement { get; init; } = new();
}

public sealed class MeasurementMetadata
{
    [JsonPropertyName("metric")]
    public string Metric { get; init; } = "baseline_adjusted_integrated_energy";

    [JsonPropertyName("baseline_duration_ms")]
    public int BaselineDurationMilliseconds { get; init; } = 2_000;

    [JsonPropertyName("duration_ms")]
    public int DurationMilliseconds { get; init; } = 5_000;

    [JsonPropertyName("baseline_dbfs")]
    public double BaselineDbfs { get; init; }

    [JsonPropertyName("mean_dbfs_a")]
    public double MeanDbfsA { get; init; }

    [JsonPropertyName("mean_dbfs_b")]
    public double MeanDbfsB { get; init; }

    [JsonPropertyName("peak_dbfs_a")]
    public double PeakDbfsA { get; init; }

    [JsonPropertyName("peak_dbfs_b")]
    public double PeakDbfsB { get; init; }
}

public sealed class ApiErrorResponse
{
    [JsonPropertyName("message")]
    public string? Message { get; init; }

    [JsonPropertyName("code")]
    public string? Code { get; init; }
}
