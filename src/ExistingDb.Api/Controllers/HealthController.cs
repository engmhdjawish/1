using Microsoft.AspNetCore.Mvc;

namespace ExistingDb.Api.Controllers;

[ApiController]
[Route("api/health")]
public sealed class HealthController : ControllerBase
{
    [HttpGet]
    public IActionResult Get() => Ok(new { status = "ok", service = "ExistingDb.Api" });
}

