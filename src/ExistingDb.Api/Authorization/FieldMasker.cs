namespace ExistingDb.Api.Authorization;

public sealed class FieldMasker : IFieldMasker
{
    public object? Mask(object? value, MaskingStrategy strategy)
    {
        if (value is null || strategy == MaskingStrategy.None)
        {
            return value;
        }

        var text = Convert.ToString(value);
        if (string.IsNullOrEmpty(text))
        {
            return text;
        }

        return strategy switch
        {
            MaskingStrategy.Email => MaskEmail(text),
            MaskingStrategy.Phone => MaskPhone(text),
            MaskingStrategy.LastFour => MaskLastFour(text),
            MaskingStrategy.Full => new string('*', Math.Min(text.Length, 8)),
            _ => value
        };
    }

    private static string MaskEmail(string value)
    {
        var atIndex = value.IndexOf('@', StringComparison.Ordinal);
        if (atIndex <= 1)
        {
            return MaskLastFour(value);
        }

        return $"{value[0]}***{value[atIndex..]}";
    }

    private static string MaskPhone(string value)
    {
        var digits = new string(value.Where(char.IsDigit).ToArray());
        return digits.Length <= 4 ? "****" : $"******{digits[^4..]}";
    }

    private static string MaskLastFour(string value)
    {
        return value.Length <= 4 ? "****" : $"{new string('*', value.Length - 4)}{value[^4..]}";
    }
}

