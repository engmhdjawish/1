namespace ExistingDb.Api.Services.Search;

public static class KeywordSearchTerms
{
    private static readonly char[] Separators = [' ', '\t', '\r', '\n', ',', '،', '|'];

    public static IReadOnlyList<string> Parse(string? keyword, string? legacySearch = null)
    {
        var text = !string.IsNullOrWhiteSpace(keyword) ? keyword : legacySearch;
        if (string.IsNullOrWhiteSpace(text))
        {
            return [];
        }

        return text
            .Split(Separators, StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
            .Where(term => !string.IsNullOrWhiteSpace(term))
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToArray();
    }
}
