using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;

namespace ExistingDb.Api.Services.Materials;

internal static class MaterialStoreInventoryQuery
{
    public static IQueryable<MaterialRecord> ApplyStoreAndQuantityFilters(
        MainDbContext mainDbContext,
        IQueryable<MaterialRecord> query,
        IReadOnlyCollection<Guid> selectedStoreGuids,
        double? minWarehouseQuantity,
        double? maxWarehouseQuantity,
        bool? isAvailable)
    {
        if (selectedStoreGuids.Count == 0)
        {
            if (isAvailable is true)
            {
                query = query.Where(material => (material.Qty ?? 0) > 0);
            }
            else if (isAvailable is false)
            {
                query = query.Where(material => (material.Qty ?? 0) <= 0);
            }

            if (minWarehouseQuantity is not null)
            {
                query = query.Where(material => (material.Qty ?? 0) >= minWarehouseQuantity.Value);
            }

            if (maxWarehouseQuantity is not null)
            {
                query = query.Where(material => (material.Qty ?? 0) <= maxWarehouseQuantity.Value);
            }

            return query;
        }

        var needsQuantityAggregate = isAvailable is not null
            || minWarehouseQuantity is not null
            || maxWarehouseQuantity is not null;

        if (!needsQuantityAggregate)
        {
            return query.Where(material => mainDbContext.MaterialInventory.Any(inventory =>
                inventory.MaterialGuid == material.Guid
                && inventory.StoreGuid.HasValue
                && selectedStoreGuids.Contains(inventory.StoreGuid.Value)));
        }

        if (isAvailable is true)
        {
            query = query.Where(material =>
                (mainDbContext.MaterialInventory
                    .Where(inventory => inventory.MaterialGuid == material.Guid
                        && inventory.StoreGuid.HasValue
                        && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
                    .Sum(inventory => (double?)(inventory.Qty ?? 0)) ?? 0) > 0);
        }
        else if (isAvailable is false)
        {
            query = query.Where(material =>
                (mainDbContext.MaterialInventory
                    .Where(inventory => inventory.MaterialGuid == material.Guid
                        && inventory.StoreGuid.HasValue
                        && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
                    .Sum(inventory => (double?)(inventory.Qty ?? 0)) ?? 0) <= 0);
        }
        else
        {
            query = query.Where(material => mainDbContext.MaterialInventory.Any(inventory =>
                inventory.MaterialGuid == material.Guid
                && inventory.StoreGuid.HasValue
                && selectedStoreGuids.Contains(inventory.StoreGuid.Value)));
        }

        if (minWarehouseQuantity is not null)
        {
            query = query.Where(material =>
                (mainDbContext.MaterialInventory
                    .Where(inventory => inventory.MaterialGuid == material.Guid
                        && inventory.StoreGuid.HasValue
                        && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
                    .Sum(inventory => (double?)(inventory.Qty ?? 0)) ?? 0) >= minWarehouseQuantity.Value);
        }

        if (maxWarehouseQuantity is not null)
        {
            query = query.Where(material =>
                (mainDbContext.MaterialInventory
                    .Where(inventory => inventory.MaterialGuid == material.Guid
                        && inventory.StoreGuid.HasValue
                        && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
                    .Sum(inventory => (double?)(inventory.Qty ?? 0)) ?? 0) <= maxWarehouseQuantity.Value);
        }

        return query;
    }
}
