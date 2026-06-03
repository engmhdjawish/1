namespace ExistingDb.Api.Contracts.Materials;

[Flags]
public enum MaterialFilterExclusions
{
    None = 0,
    CountryOfOrigins = 1,
    Manufacturers = 2,
    SizeRanges = 4,
    MaterialTypes = 8,
    AgeCategories = 16,
    Groups = 32
}
