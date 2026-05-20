namespace ExistingDb.Api.Authorization;

public interface IFieldMasker
{
    object? Mask(object? value, MaskingStrategy strategy);
}

