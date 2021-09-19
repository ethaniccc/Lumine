mod server;
use std::error::Error;

type Result<T, E = Box<dyn std::error::Error>> = std::result::Result<T, E>;

#[tokio::main]
async fn main() -> Result<()> {
    let mut server = server::new().await?;
    server.start().await?;
    Ok(())
}
