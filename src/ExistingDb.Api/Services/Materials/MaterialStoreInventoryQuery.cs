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
            // Policy warehouses are an exclusive allow-list:
            // 1) positive qty in at least one selected store
            // 2) no positive qty in any store outside the selection
            query = query.Where(material =>
                (mainDbContext.MaterialInventory
                    .Where(inventory => inventory.MaterialGuid == material.Guid
                        && inventory.StoreGuid.HasValue
                        && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
                    .Sum(inventory => (double?)(inventory.Qty ?? 0)) ?? 0) > 0
                && !mainDbContext.MaterialInventory.Any(inventory =>
                    inventory.MaterialGuid == material.Guid
                    && inventory.StoreGuid.HasValue
                    && !selectedStoreGuids.Contains(inventory.StoreGuid.Value)
                    && (inventory.Qty ?? 0) > 0));
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

    public static bool HasPositiveQuantityOutsideSelectedStores(
        MainDbContext mainDbContext,
        Guid materialGuid,
        IReadOnlyCollection<Guid> selectedStoreGuids) =>
        selectedStoreGuids.Count > 0
        && mainDbContext.MaterialInventory.Any(inventory =>
            inventory.MaterialGuid == materialGuid
            && inventory.StoreGuid.HasValue
            && !selectedStoreGuids.Contains(inventory.StoreGuid.Value)
            && (inventory.Qty ?? 0) > 0);
}
