namespace ExistingDb.Api.Services.Materials;

internal static class MaterialPictureGuid
{
    public static readonly Guid Cleared = Guid.Empty;

    public static bool HasImage(Guid? pictureGuid) =>
        pictureGuid is { } guid && guid != Guid.Empty;

    public static Guid? Normalize(Guid? pictureGuid) =>
        HasImage(pictureGuid) ? pictureGuid : null;
}
