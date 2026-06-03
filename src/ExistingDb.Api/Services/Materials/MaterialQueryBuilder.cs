using System.Linq.Expressions;
using ExistingDb.Api.Contracts.Materials;
using ExistingDb.Api.Data;
using ExistingDb.Api.Data.MainDb;
using Microsoft.EntityFrameworkCore;

namespace ExistingDb.Api.Services.Materials;

public sealed class MaterialQueryBuilder(MainDbContext mainDbContext)
{
    public IQueryable<MaterialRecord> Build(
        MaterialListFilters filters,
        MaterialFilterExclusions exclusions = MaterialFilterExclusions.None)
    {
        var query = mainDbContext.Materials.AsNoTracking();

        query = ApplyStoreAndQuantityFilters(query, filters);
        query = ApplyTextFilters(query, filters, exclusions);
        query = ApplyGroupFilter(query, filters, exclusions);
        query = ApplyPriceFilters(query, filters);

        return query;
    }

    public async Task<IQueryable<MaterialRecord>> ApplySearchFilterAsync(
        IQueryable<MaterialRecord> query,
        string? search,
        CancellationToken cancellationToken)
    {
        if (string.IsNullOrWhiteSpace(search))
        {
            return query;
        }

        var term = search.Trim();
        var exactCodeExists = await mainDbContext.Materials
            .AsNoTracking()
            .AnyAsync(material => material.Code == term, cancellationToken);

        return exactCodeExists
            ? query.Where(material => material.Code == term)
            : query.Where(material =>
                (material.Name != null && material.Name.Contains(term)) ||
                (material.LatinName != null && material.LatinName.Contains(term)) ||
                (material.Code != null && material.Code.Contains(term)) ||
                (material.BarCode != null && material.BarCode.Contains(term)) ||
                (material.BarCode2 != null && material.BarCode2.Contains(term)) ||
                (material.BarCode3 != null && material.BarCode3.Contains(term)));
    }

    private IQueryable<MaterialRecord> ApplyStoreAndQuantityFilters(
        IQueryable<MaterialRecord> query,
        MaterialListFilters filters)
    {
        var selectedStoreGuids = filters.StoreGuids;
        if (selectedStoreGuids.Count == 0)
        {
            if (filters.IsAvailable is true)
            {
                query = query.Where(material => (material.Qty ?? 0) > 0);
            }
            else if (filters.IsAvailable is false)
            {
                query = query.Where(material => (material.Qty ?? 0) <= 0);
            }

            if (filters.MinWarehouseQuantity is not null)
            {
                query = query.Where(material => (material.Qty ?? 0) >= filters.MinWarehouseQuantity.Value);
            }

            if (filters.MaxWarehouseQuantity is not null)
            {
                query = query.Where(material => (material.Qty ?? 0) <= filters.MaxWarehouseQuantity.Value);
            }

            return query;
        }

        var storeQuantities = mainDbContext.MaterialInventory
            .AsNoTracking()
            .Where(inventory => inventory.MaterialGuid.HasValue)
            .Where(inventory => inventory.StoreGuid.HasValue && selectedStoreGuids.Contains(inventory.StoreGuid.Value))
            .GroupBy(inventory => inventory.MaterialGuid!.Value)
            .Select(group => new
            {
                MaterialGuid = group.Key,
                Quantity = group.Sum(inventory => inventory.Qty ?? 0)
            });

        if (filters.IsAvailable is true)
        {
            query = query.Where(material => storeQuantities.Any(quantity =>
                quantity.MaterialGuid == material.Guid &&
                quantity.Quantity > 0));
        }
        else if (filters.IsAvailable is false)
        {
            query = query.Where(material => !storeQuantities.Any(quantity =>
                quantity.MaterialGuid == material.Guid &&
                quantity.Quantity > 0));
        }
        else
        {
            query = query.Where(material => storeQuantities.Any(quantity => quantity.MaterialGuid == material.Guid));
        }

        if (filters.MinWarehouseQuantity is not null)
        {
            query = query.Where(material => storeQuantities.Any(quantity =>
                quantity.MaterialGuid == material.Guid &&
                quantity.Quantity >= filters.MinWarehouseQuantity.Value));
        }

        if (filters.MaxWarehouseQuantity is not null)
        {
            query = query.Where(material => storeQuantities.Any(quantity =>
                quantity.MaterialGuid == material.Guid &&
                quantity.Quantity <= filters.MaxWarehouseQuantity.Value));
        }

        return query;
    }

    private static IQueryable<MaterialRecord> ApplyTextFilters(
        IQueryable<MaterialRecord> query,
        MaterialListFilters filters,
        MaterialFilterExclusions exclusions)
    {
        if ((exclusions & MaterialFilterExclusions.CountryOfOrigins) == 0 && filters.CountryOfOrigins.Count > 0)
        {
            query = ApplyContainsAny(query, material => material.Origin, filters.CountryOfOrigins);
        }

        if ((exclusions & MaterialFilterExclusions.Manufacturers) == 0 && filters.Manufacturers.Count > 0)
        {
            query = ApplyContainsAny(query, material => material.Company, filters.Manufacturers);
        }

        if ((exclusions & MaterialFilterExclusions.SizeRanges) == 0 && filters.SizeRanges.Count > 0)
        {
            query = ApplyContainsAny(query, material => material.Dim, filters.SizeRanges);
        }

        if ((exclusions & MaterialFilterExclusions.MaterialTypes) == 0 && filters.MaterialTypes.Count > 0)
        {
            query = ApplyContainsAny(query, material => material.Color, filters.MaterialTypes);
        }

        if ((exclusions & MaterialFilterExclusions.AgeCategories) == 0 && filters.AgeCategories.Count > 0)
        {
            query = ApplyContainsAny(query, material => material.Provenance, filters.AgeCategories);
        }

        return query;
    }

    private static IQueryable<MaterialRecord> ApplyGroupFilter(
        IQueryable<MaterialRecord> query,
        MaterialListFilters filters,
        MaterialFilterExclusions exclusions)
    {
        if ((exclusions & MaterialFilterExclusions.Groups) != 0 || filters.GroupGuids.Count == 0)
        {
            return query;
        }

        return query.Where(material => material.GroupGuid.HasValue && filters.GroupGuids.Contains(material.GroupGuid.Value));
    }

    private static IQueryable<MaterialRecord> ApplyPriceFilters(
        IQueryable<MaterialRecord> query,
        MaterialListFilters filters)
    {
        if (filters.MinUnitSalePriceSyp is not null)
        {
            query = query.Where(material => material.Whole >= filters.MinUnitSalePriceSyp.Value);
        }

        if (filters.MaxUnitSalePriceSyp is not null)
        {
            query = query.Where(material => material.Whole <= filters.MaxUnitSalePriceSyp.Value);
        }

        if (filters.MinUnitSalePriceUsd is not null)
        {
            query = query.Where(material => material.Half >= filters.MinUnitSalePriceUsd.Value);
        }

        if (filters.MaxUnitSalePriceUsd is not null)
        {
            query = query.Where(material => material.Half <= filters.MaxUnitSalePriceUsd.Value);
        }

        if (filters.MinUnitPurchasePriceUsd is not null)
        {
            query = query.Where(material => material.EndUser >= filters.MinUnitPurchasePriceUsd.Value);
        }

        if (filters.MaxUnitPurchasePriceUsd is not null)
        {
            query = query.Where(material => material.EndUser <= filters.MaxUnitPurchasePriceUsd.Value);
        }

        return query;
    }

    private static IQueryable<MaterialRecord> ApplyContainsAny(
        IQueryable<MaterialRecord> query,
        Expression<Func<MaterialRecord, string?>> selector,
        IReadOnlyCollection<string> values)
    {
        if (values.Count == 0)
        {
            return query;
        }

        var parameter = selector.Parameters[0];
        var property = selector.Body;
        var containsMethod = typeof(string).GetMethod(nameof(string.Contains), [typeof(string)])
            ?? throw new InvalidOperationException("string.Contains(string) method was not found.");
        var notNull = Expression.NotEqual(property, Expression.Constant(null, typeof(string)));
        Expression? body = null;

        foreach (var value in values)
        {
            var contains = Expression.Call(property, containsMethod, Expression.Constant(value));
            var clause = Expression.AndAlso(notNull, contains);
            body = body is null ? clause : Expression.OrElse(body, clause);
        }

        return query.Where(Expression.Lambda<Func<MaterialRecord, bool>>(body!, parameter));
    }
}
