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

        if (isAvailable is false)
        {
            query = query.Where(material =>
                mainDbContext.MaterialInventory.Any(inventory =>
                    inventory.MaterialGuid == material.Guid
                    && inventory.StoreGuid.HasValue
                    && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
                && (mainDbContext.MaterialInventory
                    .Where(inventory => inventory.MaterialGuid == material.Guid
                        && inventory.StoreGuid.HasValue
                        && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
                    .Sum(inventory => (double?)(inventory.Qty ?? 0)) ?? 0) <= 0);
        }
        else
        {
            // Inclusion allow-list: show when selected stores have positive qty.
            // Quantity returned to clients is summed from selected stores only
            // (see MaterialsController.GetQuantityByMaterialAsync) — stock in
            // excluded warehouses does not add to warehouseQuantity.
            query = query.Where(material =>
                (mainDbContext.MaterialInventory
                    .Where(inventory => inventory.MaterialGuid == material.Guid
                        && inventory.StoreGuid.HasValue
                        && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
                    .Sum(inventory => (double?)(inventory.Qty ?? 0)) ?? 0) > 0);
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
